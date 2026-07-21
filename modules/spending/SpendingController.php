<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Models\Family;
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
    private const CATEGORIES = ['food', 'transport', 'lodging', 'activities', 'other'];

    private const CURRENCIES = ['EUR', 'RON', 'USD', 'GBP'];

    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $events = SpendEvent::query()
            ->withSum('spends', 'amount')
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
                'total' => (int) $e->spends_sum_amount,
                'members' => $e->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
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
        $event->update($request->validate(['name' => ['required', 'string', 'max:255']]));
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

        return Storage::disk('r2')->response($receipt->path);
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
        $memberIds = $event->members()->pluck('users.id')->all();

        $data = $request->validate([
            'from_user_id' => ['required', 'integer', 'in:'.implode(',', $memberIds)],
            'to_user_id' => ['required', 'integer', 'different:from_user_id', 'in:'.implode(',', $memberIds)],
            'amount' => ['required', 'integer', 'gt:0'], // already in cents from the suggested payment
        ]);

        $event->settlements()->create([
            'from_user_id' => $data['from_user_id'],
            'to_user_id' => $data['to_user_id'],
            'amount' => $data['amount'],
            'created_by' => $request->user()->id,
        ]);

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
            Storage::disk('r2')->delete($r->path);
        }
        if ($spend->receipt_path) {
            Storage::disk('r2')->delete($spend->receipt_path); // legacy single receipt, if present
        }
        $spend->delete();
        Realtime::broadcast(new SpendingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Store any newly uploaded receipt photos (receipts[]) in private R2 as their own rows.
    private function attachReceipts(Request $request, Spend $spend): void
    {
        foreach ((array) $request->file('receipts', []) as $file) {
            $spend->receipts()->create(['path' => $file->store('receipts/'.CurrentFamily::id(), 'r2')]);
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
            Storage::disk('r2')->delete($r->path);
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
        $memberIds = $event->members()->pluck('users.id')->all();

        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', self::CURRENCIES)],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'payer_id' => ['required', 'integer', 'in:'.implode(',', $memberIds)],
            // Optional payment source; must be one of the family's (SpendSource is family-scoped).
            'source_id' => ['nullable', 'integer', 'in:'.implode(',', SpendSource::pluck('id')->all() ?: [0])],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
            'receipts' => ['nullable', 'array', 'max:10'], // up to 10 photos per spend
            'receipts.*' => ['image', 'max:12288'], // up to 12 MB each (PHP allows 16M, see Dockerfile)
            'remove_receipt_ids' => ['nullable', 'array'], // ids of existing receipts to detach (on edit)
            'remove_receipt_ids.*' => ['integer'],
        ]);
    }

    /** Build the stored columns from validated input, freezing the FX conversion at the spend's date. */
    private function spendAttributes(array $data, SpendEvent $event): array
    {
        // Decimal major units in, integer minor units stored. round() so 48.00 -> 4800 exactly.
        $amount = (int) round($data['amount'] * 100);
        $baseAmount = Fx::convert($amount, $data['currency'], $event->currency, $data['spent_at']);

        return [
            'payer_id' => $data['payer_id'],
            'source_id' => $data['source_id'] ?? null,
            'amount' => $amount,
            'currency' => $data['currency'],
            'base_amount' => $baseAmount,
            'rate' => $amount ? $baseAmount / $amount : 1,
            'category' => $data['category'],
            'note' => $data['note'] ?? null,
            'spent_at' => $data['spent_at'],
        ];
    }

    private function eventPayload(SpendEvent $event): array
    {
        $members = $event->members()->get(['users.id', 'name']);
        $memberIds = $members->pluck('id')->all();

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

        $spends = $event->spends()->with(['payer:id,name', 'source:id,name,type', 'receipts:id,spend_id'])->latest('spent_at')->latest('id')->get();
        $settlements = $event->settlements()->get();
        $sources = SpendSource::orderBy('id')->get(['id', 'name', 'type']); // creation order → newest last

        // Balances are computed in the event's base currency, so they use base_amount (each spend
        // already converted + frozen at capture). Settlements are recorded in the base currency too.
        $balances = Balances::for(
            $memberIds,
            $spends->map(fn (Spend $s) => ['payer_id' => $s->payer_id, 'amount' => $s->base_amount])->all(),
            $settlements->map(fn (SpendSettlement $t) => [
                'from_user_id' => $t->from_user_id, 'to_user_id' => $t->to_user_id, 'amount' => $t->amount,
            ])->all(),
        );

        $nameOf = fn (int $id) => $members->firstWhere('id', $id)?->name ?? '?';

        return [
            'familyId' => (int) CurrentFamily::id(),
            'scanEnabled' => ReceiptScanner::configured(),
            'sources' => $sources,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'currency' => $event->currency,
                'total' => (int) $spends->sum('base_amount'),
                'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'categories' => self::CATEGORIES,
                'currencies' => self::CURRENCIES,
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
                    'url' => route('spending.receipts.show', $r->id),
                ])->values(),
                'source' => $s->source ? ['id' => $s->source->id, 'name' => $s->source->name, 'type' => $s->source->type] : null,
                'payer' => ['id' => $s->payer_id, 'name' => $s->payer?->name],
                'spent_at' => $s->spent_at->toDateString(),
            ])->values(),
            'balances' => collect($balances['net'])
                ->map(fn (int $cents, int $id) => ['user_id' => $id, 'name' => $nameOf($id), 'net' => $cents])
                ->values(),
            'suggestions' => collect($balances['suggestions'])->map(fn ($s) => [
                'from' => ['id' => $s['from'], 'name' => $nameOf($s['from'])],
                'to' => ['id' => $s['to'], 'name' => $nameOf($s['to'])],
                'amount' => $s['amount'],
            ])->values(),
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
        ];
    }
}
