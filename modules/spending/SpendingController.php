<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use Modules\Notifications\Notify;
use App\Support\Media\StoredImage;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpendingController extends Controller
{
    // Public so the guest view (SpendEvent::guestView) can reuse the same option lists.
    public const CATEGORIES = ['food', 'transport', 'lodging', 'activities', 'shopping', 'other'];

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
            ->map(fn (SpendEvent $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'currency' => $e->currency,
                'total' => (int) $e->spends_sum_base_amount,
                'archived' => $e->archived_at !== null,
                // Only members who can actually reach the event: everyone for `family` visibility,
                // otherwise just those with a share. The pivot holds every family member (synced at
                // creation), so without this filter an invited/private event shows people who have no
                // access. Mirrors SpendEvent::participantNames().
                'members' => $e->members
                    ->filter(fn ($m) => $e->visibility() === 'family' || $e->shares->contains('user_id', $m->id))
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ])->values();

        return Inertia::render('spending/events', [
            'events' => $events,
            'currencies' => self::CURRENCIES,
        ]);
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
            $data = (new ReceiptScanner(self::CATEGORIES, self::CURRENCIES))->scan($request->file('photo'));
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
        ]);
        if (array_key_exists('archived', $data)) {
            $event->archived_at = $data['archived'] ? now() : null;
        }
        if (array_key_exists('name', $data)) {
            $event->name = $data['name'];
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
        $spend->delete();
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
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
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

        return [
            'familyId' => (int) CurrentFamily::id(),
            'scanEnabled' => ReceiptScanner::configured(),
            'sources' => $sources,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'currency' => $event->currency,
                'total' => (int) $spends->sum('base_amount'),
                'archived' => $event->archived_at !== null,
                'members' => $members->filter(fn ($m) => isset($names['u'.$m->id]))->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'people' => array_sum($split['weights']), // total headcount, not participant rows
                'categories' => self::CATEGORIES,
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
