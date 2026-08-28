<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use App\Support\Media\StoredImage;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notifications\Notify;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpendingController extends Controller
{
    public const CURRENCIES = ['EUR', 'RON', 'USD', 'GBP'];

    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $events = SpendEvent::query()
            // Sum base_amount (each spend converted to the event currency, frozen at capture), NOT the
            // raw amount — otherwise a foreign-currency spend (e.g. a RON fill-up in a EUR trip) is added
            // as if its number were euros, inflating the card total vs the event's real "Total spent".
            ->withSum('spends', 'base_amount')
            ->with(['members:id,name', 'shareSetting', 'shares'])
            ->latest()
            ->get()
            // The BelongsToFamily scope already limits to the current family (and the `family`
            // middleware guarantees the user is a member), so any `family`-visibility event is
            // visible; `invited`/`private` ones need an explicit share row.
            ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId))
            ->map(function (SpendEvent $e) {
                // Show the card total in the list's chosen display currency (the header selector on the
                // detail page), not the frozen base currency. Base amounts stay in $e->currency; we
                // convert the sum to the display currency for the overview so the two views agree.
                $display = $e->display_currency ?: $e->currency;

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'currency' => $display,
                    'total' => Fx::convert((int) $e->spends_sum_base_amount, $e->currency, $display),
                    'archived' => $e->archived_at !== null,
                    // Only members who can actually reach the event: everyone for `family` visibility,
                    // otherwise just those with a share. The pivot holds every family member (synced at
                    // creation), so without this filter an invited/private event shows people who have no
                    // access. Mirrors SpendEvent::participantNames().
                    'members' => $e->members
                        ->filter(fn ($m) => $e->visibility() === 'family' || $e->shares->contains('user_id', $m->id))
                        ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                ];
            })->values();

        return Inertia::render('spending/events', [
            'events' => $events,
            'currencies' => self::CURRENCIES,
            // Pre-select the family's default currency on the create form (falls back to the first
            // supported currency client-side when unset).
            'defaultCurrency' => SpendSetting::defaultCurrency(),
        ]);
    }

    // Module settings screen (reached from the overview header's gear): the family default currency and
    // the definable expense categories. Visible to any member; writes are admin-only (see below).
    public function settings(Request $request): Response
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $canManage = $request->user()->can('manageMembers', $family);

        // How many spends use each category key, so the UI can block deleting one that's in use and
        // still resolve its label. Counts every family spend (across lists), grouped by category key.
        $usage = Spend::query()->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');

        $categories = SpendCategory::forFamily()->map(fn (SpendCategory $c) => [
            ...$c->toPayload(),
            'id' => $c->id,
            'inUse' => (int) ($usage[$c->key] ?? 0),
        ])->values();

        return Inertia::render('spending/settings', [
            'currencies' => self::CURRENCIES,
            'defaultCurrency' => SpendSetting::defaultCurrency(),
            'categories' => $categories,
            'canManage' => $canManage,
        ]);
    }

    // Save the family's default currency (admin only). null clears it back to the auto default.
    public function updateSettings(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'default_currency' => ['nullable', 'in:'.implode(',', self::CURRENCIES)],
        ]);
        SpendSetting::current()->update(['default_currency' => $data['default_currency'] ?? null]);

        return back();
    }

    // Add a custom category (admin only). The admin supplies just a name; we derive a stable slug key
    // and assign the fallback icon + a rotating colour so it reads distinctly in the charts.
    public function storeCategory(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);

        // Seed defaults first so a brand-new family's custom rows sort after them, and colour cycling
        // counts existing rows.
        $existing = SpendCategory::forFamily();
        $familyId = (int) $family->id;
        $color = SpendCategory::CUSTOM_COLORS[$existing->count() % count(SpendCategory::CUSTOM_COLORS)];

        SpendCategory::create([
            'key' => SpendCategory::makeKey($data['name'], $familyId),
            'name' => trim($data['name']),
            'icon' => SpendCategory::CUSTOM_ICON,
            'color' => $color,
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1, // append after current categories
        ]);

        return back();
    }

    // Delete a category (admin only). Blocked while any spend still uses its key, so a delete can never
    // orphan a spend; existing spends always keep a resolvable category.
    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $cat = SpendCategory::findOrFail($category); // family-scoped resolve

        if (Spend::where('category', $cat->key)->exists()) {
            throw ValidationException::withMessages(['category' => __('spending.category_in_use')]);
        }
        $cat->delete();

        return back();
    }

    /**
     * Global spending analytics across every list the user can see: total, by-category, and a
     * per-day series for the selected period. Unlike per-event stats (one list, its own base
     * currency), this rolls up lists that may each have a different base, so everything is
     * converted to one display currency (query `currency`, default the most common base).
     */
    public function analytics(Request $request): Response
    {
        $userId = $request->user()->id;

        // Same visibility rule as index(): family-visible events, plus invited/private ones the user
        // has a share on. Archived lists are excluded. Only id+currency are needed to convert.
        $events = SpendEvent::query()
            ->with(['shareSetting', 'shares'])
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId));

        $eventCcy = $events->pluck('currency', 'id'); // event id => its frozen base currency
        $eventName = $events->pluck('name', 'id');    // event id => its list name (for the drill-down entries)

        // Optional drill-down: filter the whole view to one category. Must be a known category, otherwise
        // ignored (treated as "all"). byCategory itself is NEVER filtered — it stays the full switcher.
        $categoryKeys = SpendCategory::keysFor();
        $activeCategory = $request->query('category');
        if (! in_array($activeCategory, $categoryKeys, true)) {
            $activeCategory = null;
        }

        // Display currency: an explicit pick, else the family's default currency, else the most common
        // base among the visible lists so the default already reads in whatever currency most lists use.
        $default = SpendSetting::defaultCurrency()
            ?: ($eventCcy->countBy()->sortDesc()->keys()->first() ?? self::CURRENCIES[0]);
        $display = $request->query('currency');
        if (! in_array($display, self::CURRENCIES, true)) {
            $display = $default;
        }

        [$period, $start, $end] = $this->analyticsPeriod($request);

        // One cached rate lookup per distinct base currency (not per spend): convert each list's frozen
        // base cents into the display currency at the latest rate.
        $rate = [];
        foreach ($eventCcy->unique() as $ccy) {
            $rate[$ccy] = Fx::rate($ccy, $display);
        }
        $conv = fn (int $baseCents, string $ccy) => (int) round($baseCents * ($rate[$ccy] ?? 1.0));

        // Query the smaller dataset: only spends in-range for these events, only the columns we need
        // (id + note added for the drill-down entries list).
        $spends = $eventCcy->isEmpty() ? collect() : Spend::query()
            ->whereIn('spend_event_id', $eventCcy->keys())
            ->whereBetween('spent_at', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'spend_event_id', 'category', 'base_amount', 'note', 'spent_at']);

        // For long ranges, group the series by month instead of by day (see below): decide up front so
        // we bucket each spend into the right key while summing.
        $byMonth = $start->diffInDays($end) > 90; // > 90 days spanned => monthly buckets
        $granularity = $byMonth ? 'month' : 'day';

        $total = 0;   // display cents — honours the active-category filter
        $count = 0;   // number of spends — honours the active-category filter
        $byCat = [];  // category => display cents — ALWAYS across every category (never filtered)
        $bucket = []; // series key ('Y-m-d' day, or 'Y-m-01' month) => display cents — filtered
        $entries = []; // individual spends of the active category (drill-down), unsorted here
        foreach ($spends as $s) {
            $c = $conv((int) $s->base_amount, $eventCcy[$s->spend_event_id]);
            // byCategory spans all categories so the user can switch between them while filtered.
            $byCat[$s->category] = ($byCat[$s->category] ?? 0) + $c;

            // Everything else (total, count, series, entries) reflects the active category only.
            if ($activeCategory !== null && $s->category !== $activeCategory) {
                continue;
            }
            $total += $c;
            $count++;
            // Bucket by first-of-month for monthly granularity, else by exact day.
            $key = $byMonth ? $s->spent_at->copy()->startOfMonth()->toDateString() : $s->spent_at->toDateString();
            $bucket[$key] = ($bucket[$key] ?? 0) + $c;
            if ($activeCategory !== null) {
                $entries[] = [
                    'id' => $s->id,
                    'amount' => $c, // already in display cents
                    'note' => $s->note, // client falls back to the category label when empty
                    'category' => $s->category,
                    'date' => $s->spent_at->toDateString(),
                    'list' => $eventName[$s->spend_event_id] ?? '',
                    'list_id' => $s->spend_event_id, // parent SpendEvent id, so the row can link to its detail page
                ];
            }
        }

        // Biggest expense first.
        usort($entries, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        // byCategory percentages are relative to the grand total across all categories, so the rows stay
        // consistent (and sum to ~100%) whether or not a category filter is applied.
        $grandTotal = array_sum($byCat);
        $pct = fn (int $a) => $grandTotal ? round($a / $grandTotal * 100) : 0;
        $byCategory = collect($byCat)
            ->map(fn ($amount, $key) => ['key' => $key, 'amount' => $amount, 'pct' => $pct($amount)])
            ->sortByDesc('amount')->values();

        // Zero-fill every point across the range so the curve spans the whole selected period rather than
        // jumping between only the buckets that had spending. Daily when the range is <= 90 days; when it
        // spans more, one point per month (365 daily points render as an unreadable flat line + one spike).
        $daily = [];
        if ($byMonth) {
            for ($m = $start->copy()->startOfMonth(); $m <= $end; $m->addMonthNoOverflow()) {
                $key = $m->toDateString();
                $daily[] = ['date' => $key, 'amount' => $bucket[$key] ?? 0];
            }
        } else {
            for ($d = $start->copy(); $d <= $end; $d->addDay()) {
                $key = $d->toDateString();
                $daily[] = ['date' => $key, 'amount' => $bucket[$key] ?? 0];
            }
        }

        return Inertia::render('spending/analytics', [
            'currency' => $display,
            'currencies' => self::CURRENCIES,
            // Category metadata (colour/icon/label) so the chart + drill-down render dynamic categories.
            'categories' => SpendCategory::payloadFor(),
            'period' => $period,
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'total' => $total,
            'byCategory' => $byCategory,
            'daily' => $daily,
            'granularity' => $granularity,
            'count' => $count,
            'activeCategory' => $activeCategory,
            'entries' => $entries,
        ]);
    }

    /**
     * Resolve the analytics period into a [label, start, end] Carbon range (inclusive days).
     * Supported: mtd (default), last_month, ytd, custom (from/to query params).
     */
    private function analyticsPeriod(Request $request): array
    {
        $today = now();
        switch ($request->query('period')) {
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $start->copy()->endOfMonth();

                return ['last_month', $start, $end];
            case 'ytd':
                return ['ytd', $today->copy()->startOfYear(), $today->copy()];
            case 'custom':
                $start = $this->parseDate($request->query('from')) ?? $today->copy()->startOfMonth();
                $end = $this->parseDate($request->query('to')) ?? $today->copy();
                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                return ['custom', $start, $end];
            case 'mtd':
            default:
                return ['mtd', $today->copy()->startOfMonth(), $today->copy()];
        }
    }

    // Parse a Y-m-d query param into a Carbon date, or null if absent/malformed (falls back to a default).
    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function storeEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'in:'.implode(',', self::CURRENCIES)],
        ]);

        $event = SpendEvent::create([
            'name' => $data['name'],
            'currency' => $data['currency'],
            'created_by' => $request->user()->id,
        ]);

        // Everyone in the family joins the event by default (Phase 1: equal split among all members).
        $family = Family::findOrFail(CurrentFamily::id());
        $event->members()->sync($family->members->pluck('id'));

        // The creator owns the share (can manage visibility/roles/links). Visibility defaults to
        // `family`, so all members can add spends until the owner narrows it.
        $event->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        Notify::toAccessOf($event, 'spending', $request->user()->id, 'New spend tracker', "{$request->user()->name} started: {$event->name}", route('spending.show', $event, false));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return to_route('spending.show', $event);
    }

    public function show(Request $request, int $event): Response
    {
        $event = $this->event($event);

        // Self-heal events created before sharing existed: the original creator adopts ownership so
        // they can manage visibility/roles/links. Idempotent.
        if (! $event->shares()->where('role', 'owner')->exists()) {
            $event->shares()->firstOrCreate(['user_id' => $event->created_by], ['role' => 'owner']);
        }

        abort_unless($this->access->canView($request->user(), $event), 403);

        // Mark this event as "just opened" for the dashboard's Pick-up-where-you-left-off.
        EntityVisit::record('spend_event', $event->id);

        return Inertia::render('spending/event', $this->eventPayload($event));
    }

    // Create a reusable payment source for the family (auto-scoped by BelongsToFamily).
    public function storeSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:'.implode(',', SpendSource::TYPES)],
        ]);
        SpendSource::create($data);

        return back();
    }

    // Read a receipt photo into prefill fields for a new spend. Returns plain JSON (not an Inertia
    // redirect) because the client uses it to seed the form, then the user reviews and saves. 404 when
    // scanning isn't configured, so a missing key never looks like a server error.
    public function scanReceipt(Request $request): JsonResponse
    {
        abort_unless(ReceiptScanner::configured(), 404);
        $request->validate(['photo' => ['required', 'image', 'max:10240']]);

        try {
            $data = (new ReceiptScanner(SpendCategory::keysFor(), self::CURRENCIES))->scan($request->file('photo'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($data);
    }

    public function updateEvent(Request $request, int $event): RedirectResponse
    {
        $event = $this->event($event);
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
            // The list's display currency (top-right selector). Base amounts stay frozen to `currency`;
            // this only changes what the figures are shown in. null clears back to the base currency.
            'display_currency' => ['sometimes', 'nullable', 'in:'.implode(',', self::CURRENCIES)],
        ]);
        if (array_key_exists('archived', $data)) {
            $event->archived_at = $data['archived'] ? now() : null;
        }
        if (array_key_exists('name', $data)) {
            $event->name = $data['name'];
        }
        if (array_key_exists('display_currency', $data)) {
            $event->display_currency = $data['display_currency'];
        }
        $event->save();
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Set a participant's split settings: headcount (how many people they pay for) and an optional
    // "covered by" (another participant who absorbs their share). Key is u{userId} / g{guestId}.
    public function updateParticipant(Request $request, int $event, string $key): RedirectResponse
    {
        $event = $this->event($event);
        abort_unless($this->access->canEdit($request->user(), $event), 403);

        $names = $event->participantNames();
        abort_unless(array_key_exists($key, $names), 404);

        $data = $request->validate([
            'weight' => ['required', 'integer', 'min:1', 'max:99'],
            'covered_by' => ['nullable', 'string'],
        ]);

        $coveredBy = $data['covered_by'] ?: null;
        if ($coveredBy !== null) {
            // Must point at a different, real participant, and never form a chain: the coverer has to pay
            // their own share, and this participant can't already be covering someone else.
            abort_unless(array_key_exists($coveredBy, $names) && $coveredBy !== $key, 422, 'Invalid cover target.');
            $rows = $event->participantSettings()->get();
            abort_if((bool) $rows->firstWhere('participant_key', $coveredBy)?->covered_by, 422, 'That person is already covered by someone.');
            abort_if((bool) $rows->firstWhere('covered_by', $key), 422, 'This person already covers someone, so they can\'t be covered.');
        }

        // Keep the table sparse: the default (weight 1, no cover) is stored as the absence of a row.
        if ($data['weight'] === 1 && $coveredBy === null) {
            $event->participantSettings()->where('participant_key', $key)->delete();
        } else {
            $event->participantSettings()->updateOrCreate(
                ['participant_key' => $key],
                ['weight' => $data['weight'], 'covered_by' => $coveredBy],
            );
        }

        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function stats(Request $request, int $event): Response
    {
        $event = $this->event($event);
        abort_unless($this->access->canView($request->user(), $event), 403);

        // All figures in the event's base currency (cents). base_amount is frozen at capture.
        $spends = $event->spends()->with(['payer:id,name', 'source:id,name,type'])->get();
        $members = $event->members()->get(['users.id', 'name']);
        $total = (int) $spends->sum('base_amount');
        $pct = fn (int $amount) => $total ? round($amount / $total * 100) : 0;

        $byCategory = $spends->groupBy('category')
            ->map(fn ($g, $cat) => ['key' => $cat, 'amount' => (int) $g->sum('base_amount')])
            ->sortByDesc('amount')->values()
            ->map(fn ($r) => [...$r, 'pct' => $pct($r['amount'])]);

        // Daily totals in chronological order, with a running cumulative for the trend curve.
        $running = 0;
        $overTime = $spends->groupBy(fn (Spend $s) => $s->spent_at->toDateString())
            ->map(fn ($g) => (int) $g->sum('base_amount'))
            ->sortKeys()
            ->map(function (int $amount, string $date) use (&$running) {
                $running += $amount;

                return ['date' => $date, 'amount' => $amount, 'cumulative' => $running];
            })->values();

        // Split by how each spend was paid; spends without a source fall into a "Not specified" bucket.
        $bySource = $spends->groupBy(fn (Spend $s) => $s->source_id ?? 'none')
            ->map(fn ($g) => [
                'key' => (string) ($g->first()->source_id ?? 'none'),
                'label' => $g->first()->source?->name ?? 'Not specified',
                'type' => $g->first()->source?->type ?? 'none',
                'amount' => (int) $g->sum('base_amount'),
            ])
            ->sortByDesc('amount')->values()
            ->map(fn ($r) => [...$r, 'pct' => $pct($r['amount'])]);

        $biggest = $spends->sortByDesc('base_amount')->first();

        return Inertia::render('spending/stats', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'currency' => $event->currency, 'total' => $total],
            'categories' => SpendCategory::payloadFor(),
            'byCategory' => $byCategory,
            'bySource' => $bySource,
            'overTime' => $overTime,
            'facts' => [
                'count' => $spends->count(),
                'average' => $spends->count() ? (int) round($total / $spends->count()) : 0,
                'dailyAverage' => $overTime->count() ? (int) round($total / $overTime->count()) : 0,
                'biggest' => $biggest ? ['amount' => (int) $biggest->base_amount, 'label' => $biggest->note ?: ucfirst($biggest->category)] : null,
                'days' => $overTime->count(),
                'people' => $members->count(),
                'perPerson' => $members->count() ? (int) round($total / $members->count()) : 0,
            ],
        ]);
    }

    public function storeSpend(Request $request, int $event): RedirectResponse
    {
        $event = $this->event($event);
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $data = $this->validateSpend($request, $event);

        $spend = $event->spends()->create([
            ...$this->spendAttributes($data, $event),
            'created_by' => $request->user()->id,
        ]);

        $this->attachReceipts($request, $spend);
        $this->syncShares($spend, $data);

        $amount = $data['currency'].' '.number_format((float) $data['amount'], 2);
        $body = "{$request->user()->name} added {$amount}".(! empty($data['note']) ? " · {$data['note']}" : '');
        Notify::toAccessOf($event, 'spending', $request->user()->id, $event->name, $body, route('spending.show', $event, false));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateSpend(Request $request, int $spend): RedirectResponse
    {
        $spend = Spend::findOrFail($spend); // family-scoped resolve (see event() note)
        $event = $this->event($spend->spend_event_id);
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $data = $this->validateSpend($request, $event);

        // Re-freeze the conversion, since amount/currency/date may have changed.
        $spend->update($this->spendAttributes($data, $event));

        $this->removeReceipts($request, $spend);
        $this->attachReceipts($request, $spend);
        $this->syncShares($spend, $data);

        $amount = $data['currency'].' '.number_format((float) $data['amount'], 2);
        Notify::toAccessOf($event, 'spending', $request->user()->id, $event->name, "{$request->user()->name} edited {$amount}", route('spending.show', $event, false));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Streams one receipt photo from private R2. Resolving the parent spend applies the family scope;
    // the sharing check limits it to people who can view the event.
    public function receiptFile(int $receipt): StreamedResponse
    {
        $receipt = SpendReceipt::findOrFail($receipt);
        $spend = Spend::findOrFail($receipt->spend_id);
        abort_unless($this->access->canView(request()->user(), $this->event($spend->spend_event_id)), 403);

        // ?v=thumb serves the small variant (previews/library); default serves the full image.
        return StoredImage::response($receipt->path, request()->query('v'));
    }

    public function duplicateSpend(int $spend): RedirectResponse
    {
        $spend = Spend::findOrFail($spend);
        abort_unless($this->access->canEdit(request()->user(), $this->event($spend->spend_event_id)), 403);
        // Replicate keeps every attribute (currency, frozen base_amount, rate, date) except the id.
        $spend->replicate()->save();
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Move an expense to another spending list. The per-item split points at THIS list's people and the
    // amount is frozen to THIS list's currency, so on move we reset the split to "everyone" and re-freeze
    // the amount into the target list's currency. A GUEST-paid expense can't move (the guest only exists
    // in this list); a member payer becomes a participant of the target via their moved spend.
    public function moveSpend(Request $request, int $spend): RedirectResponse
    {
        $spend = Spend::findOrFail($spend);
        $source = $this->event($spend->spend_event_id);
        abort_unless($this->access->canEdit($request->user(), $source), 403);

        $data = $request->validate(['target_event_id' => ['required', 'integer']]);
        $target = $this->event($data['target_event_id']); // family-scoped resolve (404 if not this family)
        abort_unless($this->access->canEdit($request->user(), $target), 403);

        if ($target->id === $source->id) {
            throw ValidationException::withMessages(['target_event_id' => __('spending.move_same_list')]);
        }
        // Only a guest payer can't move — the guest entity belongs to the source list. A member payer is
        // fine: participantNames() already counts anyone who fronted a spend, so once the expense lands
        // in the target the payer is a participant there (and we make sure they're on its roster).
        if ($spend->payer_guest_id !== null) {
            throw ValidationException::withMessages(['target_event_id' => __('spending.move_guest_blocked')]);
        }
        $target->members()->syncWithoutDetaching([$spend->payer_id]);

        // Re-freeze the amount into the target list's currency and move it; drop the per-item split
        // (it references the old list's participants) so it defaults to splitting among everyone.
        $rebuilt = $target->buildSpend([
            'amount' => $spend->amount / 100,
            'currency' => $spend->currency,
            'spent_at' => optional($spend->spent_at)->toDateString() ?? now()->toDateString(),
            'source_id' => $spend->source_id,
            'category' => $spend->category,
            'note' => $spend->note,
        ]);
        $spend->update(['spend_event_id' => $target->id] + $rebuilt);
        $spend->shares()->delete();

        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return to_route('spending.show', $target->id);
    }

    public function settle(Request $request, int $event): RedirectResponse
    {
        $event = $this->event($event);
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $participantKeys = array_keys($event->participantNames());

        // from/to are namespaced participant keys, so a transfer can involve a member OR a guest.
        $data = $request->validate([
            'from_key' => ['required', 'string', 'in:'.implode(',', $participantKeys)],
            'to_key' => ['required', 'string', 'different:from_key', 'in:'.implode(',', $participantKeys)],
            'amount' => ['required', 'integer', 'gt:0'], // already in cents from the suggested payment
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $event->settlements()->create([
            'from_key' => $data['from_key'],
            'to_key' => $data['to_key'],
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $amount = $event->currency.' '.number_format($data['amount'] / 100, 2);
        Notify::toAccessOf($event, 'spending', $request->user()->id, 'Settle-up', "{$request->user()->name} recorded a {$amount} payment", route('spending.show', $event, false));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroySpend(int $spend): RedirectResponse
    {
        // Resolve through the scoped query (not implicit binding): the family global scope only
        // applies once SetActiveFamily has run, which is after route-model binding. So a spend from
        // another family 404s here instead of leaking.
        $spend = Spend::findOrFail($spend);
        abort_unless($this->access->canEdit(request()->user(), $this->event($spend->spend_event_id)), 403);
        // Remove each receipt's R2 object; the rows cascade-delete with the spend.
        foreach ($spend->receipts as $r) {
            StoredImage::delete($r->path); // removes the original + its thumb
        }
        if ($spend->receipt_path) {
            Storage::disk('r2')->delete($spend->receipt_path); // legacy single receipt, if present
        }
        $event = $this->event($spend->spend_event_id);
        $amount = $spend->currency.' '.number_format($spend->amount / 100, 2);
        $spend->delete();

        Notify::toAccessOf($event, 'spending', request()->user()->id, $event->name, request()->user()->name." removed {$amount}", route('spending.show', $event, false));
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Store any newly uploaded receipt photos (receipts[]) in private R2 as their own rows, each with
    // its client-generated thumbnail (receipts_thumb[], aligned by index) so previews load small.
    private function attachReceipts(Request $request, Spend $spend): void
    {
        $thumbs = (array) $request->file('receipts_thumb', []);
        foreach ((array) $request->file('receipts', []) as $i => $file) {
            $spend->receipts()->create(['path' => StoredImage::store($file, $thumbs[$i] ?? null, 'receipts/'.CurrentFamily::id())]);
        }
    }

    // Delete the receipts whose ids the client listed (remove_receipt_ids[]), including their R2 objects.
    private function removeReceipts(Request $request, Spend $spend): void
    {
        $ids = array_filter((array) $request->input('remove_receipt_ids', []));
        if (! $ids) {
            return;
        }
        foreach ($spend->receipts()->whereIn('id', $ids)->get() as $r) {
            StoredImage::delete($r->path);
            $r->delete();
        }
    }

    // Tenant-scoped lookup. Implicit route-model binding runs before SetActiveFamily sets the family
    // context, so binding a SpendEvent directly would bypass the family scope; resolving here (after
    // all middleware) applies it and 404s cross-family access.
    private function event(int $id): SpendEvent
    {
        return SpendEvent::findOrFail($id);
    }

    private function validateSpend(Request $request, SpendEvent $event): array
    {
        // Payer can be any participant on the event: a family member ('u{id}') OR a guest we shared
        // with ('g{id}'). Same namespaced keys as the split, so guests are payable just like members.
        $participantKeys = array_keys($event->participantNames());

        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', self::CURRENCIES)],
            'category' => ['required', 'in:'.implode(',', SpendCategory::keysFor())],
            'payer_key' => ['required', 'string', 'in:'.implode(',', $participantKeys)],
            // Optional payment source; must be one of the family's (SpendSource is family-scoped).
            'source_id' => ['nullable', 'integer', 'in:'.implode(',', SpendSource::pluck('id')->all() ?: [0])],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
            'receipts' => ['nullable', 'array', 'max:10'], // up to 10 photos per spend
            'receipts.*' => ['image', 'max:12288'], // up to 12 MB each (PHP allows 16M, see Dockerfile)
            'receipts_thumb' => ['nullable', 'array', 'max:10'],   // small client-generated variants, aligned by index
            'receipts_thumb.*' => ['image', 'max:4096'],
            'remove_receipt_ids' => ['nullable', 'array'], // ids of existing receipts to detach (on edit)
            'remove_receipt_ids.*' => ['integer'],
            // How the spend splits: all (everyone), some (a subset), exact (per-person amounts).
            'split' => ['nullable', 'in:all,some,exact'],
            'shares' => ['nullable', 'array'],
            'shares.*.key' => ['required_with:shares', 'in:'.implode(',', $participantKeys)],
            'shares.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    // Persist a spend's split override rows from the validated input. Amounts are stored in the spend's
    // OWN currency (minor units); EventBalances converts them to the base currency for the maths.
    private function syncShares(Spend $spend, array $data): void
    {
        $spend->shares()->delete();
        $split = $data['split'] ?? 'all';
        $shares = $data['shares'] ?? [];
        if ($split === 'all' || $shares === []) {
            return; // no rows = split among everyone (weighted)
        }

        foreach ($shares as $sh) {
            $spend->shares()->create([
                'participant_key' => $sh['key'],
                'amount' => $split === 'exact' ? (int) round(($sh['amount'] ?? 0) * 100) : null,
            ]);
        }
    }

    /** Build the stored columns from validated input, freezing the FX conversion at the spend's date. */
    private function spendAttributes(array $data, SpendEvent $event): array
    {
        // 'u{id}' -> member payer, 'g{id}' -> guest payer. Exactly one column is set; the other stays null.
        $isGuest = str_starts_with($data['payer_key'], 'g');
        $payerId = (int) substr($data['payer_key'], 1);

        return [
            'payer_id' => $isGuest ? null : $payerId,
            'payer_guest_id' => $isGuest ? $payerId : null,
            ...$event->buildSpend($data),
        ];
    }

    private function eventPayload(SpendEvent $event): array
    {
        $members = $event->members()->get(['users.id', 'name']);

        // Sharing state for the "Share event" sheet: each family member's effective role, the current
        // visibility, whether this viewer can manage, and the read-only invite link (if any).
        $visibility = $event->visibility();
        $sharesByUser = $event->shares()->get()->keyBy('user_id');
        $family = Family::findOrFail(CurrentFamily::id());
        $shareMembers = $family->members->map(function ($m) use ($sharesByUser, $visibility) {
            $explicit = $sharesByUser->get($m->id)?->role;

            return [
                'id' => $m->id,
                'name' => $m->name,
                'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null),
                'isOwner' => $explicit === 'owner',
            ];
        })->values();
        $link = $event->shareLinks()->first();

        $spends = $event->spends()->with(['payer:id,name', 'payerGuest:id,name', 'source:id,name,type', 'receipts:id,spend_id', 'shares'])->latest('spent_at')->latest('id')->get();
        $settlements = $event->settlements()->get();
        $sources = SpendSource::orderBy('id')->get(['id', 'name', 'type']); // creation order → newest last

        // Balances span family members AND guests, in the event's base currency (see EventBalances).
        $balances = EventBalances::of($event, $spends, $settlements);
        $guestLink = $event->guestLink;

        // Participants for the Settings tab: every member + guest with their split settings (headcount
        // + covered_by). Keyed by u{id}/g{id} to match the balance keys.
        $split = $event->splitSettings();
        $names = $event->participantNames();
        $participants = collect($names)->map(fn ($name, $key) => [
            'key' => $key,
            'name' => $name,
            'kind' => str_starts_with($key, 'u') ? 'member' : 'guest',
            'weight' => $split['weights'][$key] ?? 1,
            'coveredBy' => $split['coveredBy'][$key] ?? null,
        ])->values();

        // Recorded settle-up payments (the Transfers tab). from/to are participant keys (member or guest).
        $keyName = fn (string $key) => $names[$key] ?? '?';
        $transfers = $settlements->sortByDesc('id')->map(fn (SpendSettlement $t) => [
            'id' => $t->id,
            'from' => $keyName($t->from_key),
            'to' => $keyName($t->to_key),
            'amount' => $t->amount,
            'note' => $t->note,
            'date' => $t->created_at?->toDateString(),
        ])->values();

        // Personal breakdown for the viewer (only if they're a participant in this event). Everything in
        // base cents. net = paid - share + sent - received (see Balances), so share is derived from the rest.
        $userId = request()->user()->id;
        $myKey = 'u'.$userId;
        $mySummary = $members->contains('id', $userId) ? (function () use ($userId, $myKey, $spends, $settlements, $balances) {
            $paid = (int) $spends->where('payer_id', $userId)->sum('base_amount');
            $sent = (int) $settlements->where('from_key', $myKey)->sum('amount');
            $received = (int) $settlements->where('to_key', $myKey)->sum('amount');
            $net = (int) (collect($balances['balances'])->firstWhere('id', 'u'.$userId)['net'] ?? 0);

            return ['paid' => $paid, 'share' => $paid + $sent - $received - $net, 'sent' => $sent, 'received' => $received, 'net' => $net];
        })() : null;

        // Other lists this expense can be moved to: the family's other non-archived events the viewer
        // can edit. currency lets the form note when a move will re-convert the amount.
        $moveTargets = SpendEvent::where('id', '!=', $event->id)->whereNull('archived_at')
            ->with(['shareSetting', 'shares'])->get()
            ->filter(fn (SpendEvent $e) => $this->access->canEdit(request()->user(), $e))
            ->map(fn (SpendEvent $e) => ['id' => $e->id, 'name' => $e->name, 'currency' => $e->currency])
            ->values();

        return [
            'familyId' => (int) CurrentFamily::id(),
            'scanEnabled' => ReceiptScanner::configured(),
            'sources' => $sources,
            'moveTargets' => $moveTargets,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'currency' => $event->currency,
                // The saved display currency (falls back to the base). The header selector persists this.
                'displayCurrency' => $event->display_currency ?: $event->currency,
                'total' => (int) $spends->sum('base_amount'),
                'archived' => $event->archived_at !== null,
                'members' => $members->filter(fn ($m) => isset($names['u'.$m->id]))->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'people' => array_sum($split['weights']), // total headcount, not participant rows
                // Full category metadata (key/label/icon/colour) so the picker + spend log render the
                // family's dynamic categories, not a hardcoded list.
                'categories' => SpendCategory::payloadFor(),
                'currencies' => self::CURRENCIES,
                // The payer + settle-up dropdowns must match the split: only members actually on the
                // event (family visibility or an explicit share, or who fronted a spend). $names already
                // encodes that access rule, so a family member the event isn't shared with (e.g. a
                // spouse) doesn't leak into "Paid by". Guests are handled separately (they can't be
                // selected as payer here).
                // Latest rates from the base currency to every supported currency, so the client can
                // convert any base figure to a chosen display currency without another round-trip.
                'rates' => Fx::ratesFrom($event->currency, self::CURRENCIES),
            ],
            'spends' => $spends->map(fn (Spend $s) => [
                'id' => $s->id,
                'amount' => $s->amount,           // original amount, in this spend's currency
                'currency' => $s->currency,
                'base_amount' => $s->base_amount, // converted to the event currency
                'category' => $s->category,
                'note' => $s->note,
                'receipts' => $s->receipts->map(fn (SpendReceipt $r) => [
                    'id' => $r->id,
                    'url' => route('spending.receipts.show', $r->id),                 // full image (lightbox)
                    'thumbUrl' => route('spending.receipts.show', $r->id).'?v=thumb', // small variant (previews)
                ])->values(),
                'source' => $s->source ? ['id' => $s->source->id, 'name' => $s->source->name, 'type' => $s->source->type] : null,
                // A user paid (payer.id set) or a guest did (isGuest, no user id to prefill the form with).
                'payer' => ['key' => $s->participantKey(), 'id' => $s->payer_id, 'name' => $s->payer?->name ?? $s->payerGuest?->name, 'isGuest' => $s->payer_guest_id !== null],
                // How this spend splits, for prefilling the edit form. Exact amounts are in the spend's
                // own currency (major units); 'some' rows carry no amount.
                'split' => $s->shares->isEmpty() ? 'all' : ($s->shares->whereNotNull('amount')->isNotEmpty() ? 'exact' : 'some'),
                'shares' => $s->shares->map(fn (SpendShare $sh) => ['key' => $sh->participant_key, 'amount' => $sh->amount === null ? null : $sh->amount / 100])->values(),
                'spent_at' => $s->spent_at->toDateString(),
            ])->values(),
            'balances' => $balances['balances'],
            'suggestions' => $balances['suggestions'],
            'participants' => $participants,
            'transfers' => $transfers,
            'mySummary' => $mySummary,
            'share' => [
                'type' => 'spend_event',
                'id' => $event->id,
                'visibility' => $visibility,
                'canManage' => $this->access->canManage(request()->user(), $event),
                'members' => $shareMembers,
                'link' => $link ? [
                    'id' => $link->id,
                    'token' => $link->token,
                    'url' => route('share.public', $link->token),
                ] : null,
            ],
            // Guest sharing: named, account-less participants + the unguessable (optionally password-
            // gated) link. Managed through the generic /guest-links/{type}/{id} endpoints.
            'guestShare' => [
                'type' => 'spend_event',
                'id' => $event->id,
                'canManage' => $this->access->canManage(request()->user(), $event),
                'enabled' => $guestLink !== null,
                'hasPassword' => (bool) $guestLink?->hasPassword(),
                'url' => $guestLink ? route('guest.public', $guestLink->token) : null,
                'guests' => $event->guests->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'role' => $g->role])->values(),
            ],
        ];
    }
}
