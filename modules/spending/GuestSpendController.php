<?php

namespace Modules\Spending;

use App\Events\SpendingChanged;
use App\Http\Controllers\Controller;
use App\Support\Guests\GuestAccess;
use App\Support\Realtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// Guest-initiated spend writes through the guest link. No auth: every request is authorized by the
// link token + the acting guest's role (GuestAccess). A guest may only touch spends they themselves
// paid, so one guest can't rewrite another's entries.
class GuestSpendController extends Controller
{
    public function __construct(private GuestAccess $access) {}

    public function store(Request $request, string $token): RedirectResponse
    {
        [$event, $guest] = $this->access->authorizeWrite($token, (int) $request->input('guest_id'));
        $data = $this->validateSpend($request);

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
        $spend->update($event->buildSpend($this->validateSpend($request)));

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

    // The spend must belong to this event AND have been paid by the acting guest (own-entries only).
    private function ownSpend(SpendEvent $event, $guest, int $spendId): Spend
    {
        return $event->spends()
            ->where('payer_guest_id', $guest->id)
            ->whereKey($spendId)
            ->firstOrFail();
    }

    private function validateSpend(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'in:'.implode(',', SpendingController::CURRENCIES)],
            'category' => ['required', 'in:'.implode(',', SpendingController::CATEGORIES)],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['required', 'date'],
        ]);
    }
}
