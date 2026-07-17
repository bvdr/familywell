<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
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

    public function index(): Response
    {
        $events = SpendEvent::query()
            ->withSum('spends', 'amount')
            ->with('members:id,name')
            ->latest()
            ->get()
            ->map(fn (SpendEvent $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'currency' => $e->currency,
                'total' => (int) $e->spends_sum_amount,
                'members' => $e->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ]);

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

        SpendingChanged::dispatch((int) CurrentFamily::id());

        return to_route('spending.show', $event);
    }

    public function show(int $event): Response
    {
        return Inertia::render('spending/event', $this->eventPayload($this->event($event)));
    }

    public function storeSpend(Request $request, int $event): RedirectResponse
    {
        $event = $this->event($event);
        $data = $this->validateSpend($request, $event);

        $spend = $event->spends()->create([
            ...$this->spendAttributes($data, $event),
            'created_by' => $request->user()->id,
        ]);

        if ($request->hasFile('receipt')) {
            $spend->update(['receipt_path' => $this->storeReceipt($request)]);
        }

        SpendingChanged::dispatch((int) CurrentFamily::id());

        return back();
    }

    public function updateSpend(Request $request, int $spend): RedirectResponse
    {
        $spend = Spend::findOrFail($spend); // family-scoped resolve (see event() note)
        $event = $this->event($spend->spend_event_id);
        $data = $this->validateSpend($request, $event);

        // Re-freeze the conversion, since amount/currency/date may have changed.
        $spend->update($this->spendAttributes($data, $event));

        if ($request->hasFile('receipt')) {
            $old = $spend->receipt_path;
            $spend->update(['receipt_path' => $this->storeReceipt($request)]);
            if ($old) {
                Storage::disk('r2')->delete($old);
            }
        }

        SpendingChanged::dispatch((int) CurrentFamily::id());

        return back();
    }

    // Streams a spend's receipt from private R2. Family-scoped resolve keeps it to this family only.
    public function receipt(int $spend): StreamedResponse
    {
        $spend = Spend::findOrFail($spend);
        abort_unless($spend->receipt_path, 404);

        return Storage::disk('r2')->response($spend->receipt_path);
    }

    public function duplicateSpend(int $spend): RedirectResponse
    {
        // Replicate keeps every attribute (currency, frozen base_amount, rate, date) except the id.
        Spend::findOrFail($spend)->replicate()->save();
        SpendingChanged::dispatch((int) CurrentFamily::id());

        return back();
    }

    public function settle(Request $request, int $event): RedirectResponse
    {
        $event = $this->event($event);
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

        SpendingChanged::dispatch((int) CurrentFamily::id());

        return back();
    }

    public function destroySpend(int $spend): RedirectResponse
    {
        // Resolve through the scoped query (not implicit binding): the family global scope only
        // applies once SetActiveFamily has run, which is after route-model binding. So a spend from
        // another family 404s here instead of leaking.
        $spend = Spend::findOrFail($spend);
        if ($spend->receipt_path) {
            Storage::disk('r2')->delete($spend->receipt_path);
        }
        $spend->delete();
        SpendingChanged::dispatch((int) CurrentFamily::id());

        return back();
    }

    // Store an uploaded receipt in private R2 under the family's namespace; returns the object key.
    private function storeReceipt(Request $request): string
    {
        return $request->file('receipt')->store('receipts/'.CurrentFamily::id(), 'r2');
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
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
            'receipt' => ['nullable', 'image', 'max:8192'], // up to 8 MB
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

        $spends = $event->spends()->with('payer:id,name')->latest('spent_at')->latest('id')->get();
        $settlements = $event->settlements()->get();

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
                'receipt_url' => $s->receipt_path ? route('spending.spends.receipt', $s->id) : null,
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
        ];
    }
}
