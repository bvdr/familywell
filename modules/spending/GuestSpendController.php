<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Support\Guests\GuestAccess;
use App\Support\Realtime;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// Guest-initiated spend writes through the guest link. No auth: every request is authorized by the
// link token + the acting guest's role (GuestAccess). A guest may only touch spends they themselves
// paid, so one guest can't rewrite another's entries.
class GuestSpendController extends Controller
{
    public function __construct(private GuestAccess $access) {}

    public function store(Request $request, string $token): RedirectResponse
    {
        [$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'));
        $data = $this->validateSpend($request, $event);
        $this->warnIfDuplicate($request, $event, $data);

        $event->spends()->create([
            'payer_guest_id' => $guest->id,
            'family_id' => $event->family_id,       // GuestAccess set the family context, but be explicit
            'created_by' => $event->created_by,     // no user session; attribute to the event owner
            ...$event->buildSpend($data),
        ]);

        Realtime::broadcast(new SpendingChanged((int) $event->family_id));

        return back();
    }

    public function update(Request $request, string $token, int $spend): RedirectResponse
    {
        [$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'));
        $spend = $this->ownSpend($event, $guest, $spend);
        $data = $this->validateSpend($request, $event);
        $this->warnIfDuplicate($request, $event, $data, $spend->id);
        $spend->update($event->buildSpend($data));

        Realtime::broadcast(new SpendingChanged((int) $event->family_id));

        return back();
    }

    public function destroy(Request $request, string $token, int $spend): RedirectResponse
    {
        [$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'));
        $this->ownSpend($event, $guest, $spend)->delete();

        Realtime::broadcast(new SpendingChanged((int) $event->family_id));

        return back();
    }

    // Record a settle-up payment involving this guest. Own-entries only: the guest must be one side of
    // the transfer, so a guest can't record a payment between two other people. from/to are namespaced
    // participant keys ('u{id}' / 'g{id}'), same as the owner settle route.
    public function settle(Request $request, string $token): RedirectResponse
    {
        [$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'));
        $participantKeys = array_keys($event->participantNames());

        $data = $request->validate([
            'from_key' => ['required', 'string', 'in:'.implode(',', $participantKeys)],
            'to_key' => ['required', 'string', 'different:from_key', 'in:'.implode(',', $participantKeys)],
            'amount' => ['required', 'integer', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $myKey = 'g'.$guest->id;
        abort_unless($data['from_key'] === $myKey || $data['to_key'] === $myKey, 403, 'You can only record a payment involving yourself.');
        // Same soft "already recorded?" warning as the member form (SpendSettlement::looksDuplicate).
        if (! $request->boolean('add_anyway') && SpendSettlement::looksDuplicate($event, $data['from_key'], $data['to_key'], (int) $data['amount'])) {
            throw ValidationException::withMessages(['add_anyway' => Translations::get('spending.transfer_duplicate_warning')]);
        }

        $event->settlements()->create([
            'from_key' => $data['from_key'],
            'to_key' => $data['to_key'],
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
            'created_by' => $event->created_by, // no user session; attribute to the event owner
        ]);

        Realtime::broadcast(new SpendingChanged((int) $event->family_id));

        return back();
    }

    // The spend must belong to this event AND have been paid by the acting guest (own-entries only).
    private function ownSpend(SpendEvent $event, $guest, int $spendId): Spend
    {
        return $event->spends()
            ->where('payer_guest_id', $guest->id)
            ->whereKey($spendId)
            ->firstOrFail();
    }

    // Same soft duplicate warning as the member form (see SpendingController::warnIfDuplicate), bounded to
    // THIS list — the only one the guest link opens — so nothing else of the family is revealed.
    private function warnIfDuplicate(Request $request, SpendEvent $event, array $data, ?int $exceptId = null): void
    {
        if (! $request->boolean('add_anyway') && Spend::looksDuplicate($data, [$event->id], $exceptId)) {
            throw ValidationException::withMessages(['add_anyway' => Translations::get('spending.duplicate_warning')]);
        }
    }

    private function validateSpend(Request $request, SpendEvent $event): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', SpendingController::CURRENCIES)],
            'category' => ['required', 'in:'.implode(',', SpendCategory::keysFor((int) $event->family_id))],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
        ]);
    }
}
