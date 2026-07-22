<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Support\Guests\GuestableRegistry;
use App\Support\Sharing\ShareAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// Generic owner-side management of guest sharing for any registered guestable entity, addressed as
// {type}/{id}. Only the entity's owner (per the core sharing roles) may manage the link and guests.
class GuestLinkController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    // Turn the guest link on (mint a token) or off (delete the link + its password).
    public function update(Request $request, string $type, int $id)
    {
        $entity = $this->manageable($type, $id);
        $request->boolean('enabled')
            ? $entity->enableGuestLink($request->user()->id)
            : $entity->disableGuestLink();

        return back();
    }

    // Set or clear the link password (empty string clears it -> link shared freely).
    public function password(Request $request, string $type, int $id)
    {
        $entity = $this->manageable($type, $id);
        $password = $request->validate(['password' => ['nullable', 'string', 'max:255']])['password'] ?? null;

        // Setting a password is meaningless without a live link; enabling first keeps the UI honest.
        $link = $entity->enableGuestLink($request->user()->id);
        $link->update(['password' => filled($password) ? bcrypt($password) : null]);

        return back();
    }

    public function storeGuest(Request $request, string $type, int $id)
    {
        $entity = $this->manageable($type, $id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'role' => ['required', 'in:'.implode(',', Guest::ROLES)],
        ]);
        $entity->guests()->create($data);

        return back();
    }

    public function updateGuest(Request $request, string $type, int $id, Guest $guest)
    {
        $entity = $this->manageable($type, $id);
        $this->assertBelongs($guest, $entity);
        $guest->update($request->validate(['role' => ['required', 'in:'.implode(',', Guest::ROLES)]]));

        return back();
    }

    public function destroyGuest(string $type, int $id, Guest $guest)
    {
        $entity = $this->manageable($type, $id);
        $this->assertBelongs($guest, $entity);
        $guest->delete();

        return back();
    }

    // A guest addressed under {type}/{id} must actually belong to that entity (no cross-entity edits).
    private function assertBelongs(Guest $guest, Model $entity): void
    {
        abort_unless(
            $guest->guestable_id === $entity->getKey() && $guest->guestable_type === $entity->getMorphClass(),
            404
        );
    }

    // Resolve the entity and assert the current user owns it (only role that may manage sharing).
    private function manageable(string $type, int $id): Model
    {
        $entity = GuestableRegistry::resolve($type, $id);
        abort_unless($this->access->canManage(request()->user(), $entity), 403);

        return $entity;
    }
}
