<?php

namespace App\Support\Guests;

use App\Models\Guest;
use App\Models\GuestLink;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

// Authorization for the unauthenticated guest paths. The link token proves the caller reached the
// entity; an optional password is a second gate held in the session. Modules call authorizeWrite()
// to gate guest-initiated writes (e.g. adding a spend) by the acting guest's role.
class GuestAccess
{
    public function link(string $token): GuestLink
    {
        return GuestLink::where('token', $token)->firstOrFail();
    }

    // Locked = a password is set and this session hasn't cleared it yet.
    public function locked(GuestLink $link): bool
    {
        return $link->hasPassword() && ! session()->get($this->sessionKey($link->token));
    }

    // Validate the password and remember success in the session. A link with no password is always open.
    public function unlock(GuestLink $link, ?string $password): bool
    {
        if (! $link->hasPassword()) {
            return true;
        }
        if ($password !== null && Hash::check($password, $link->password)) {
            session()->put($this->sessionKey($link->token), true);

            return true;
        }

        return false;
    }

    // Resolve the entity behind a link and scope the request to its family, so tenant-owned writes
    // (Spend etc.) auto-fill family_id and realtime broadcasts reach the right family. The token has
    // already authenticated the entity, so adopting its family context is legitimate.
    public function entity(GuestLink $link): Model
    {
        $entity = $link->guestable;
        abort_unless($entity instanceof ProvidesGuestView, 404);

        if ($familyId = $entity->family_id ?? null) {
            CurrentFamily::set($familyId);
        }

        return $entity;
    }

    /**
     * Gate a guest-initiated write: link exists + unlocked, and the acting guest belongs to the entity
     * and may edit. Returns [entity, guest] for the caller to use.
     *
     * @return array{0: Model, 1: Guest}
     */
    public function authorizeWrite(string $token, int $guestId): array
    {
        $link = $this->link($token);
        abort_if($this->locked($link), 403, 'This link is locked.');
        $entity = $this->entity($link);

        $guest = Guest::where('guestable_type', $entity->getMorphClass())
            ->where('guestable_id', $entity->getKey())
            ->whereKey($guestId)
            ->firstOrFail();
        abort_unless($guest->canEdit(), 403, 'This guest is view-only.');

        return [$entity, $guest];
    }

    private function sessionKey(string $token): string
    {
        return "guest_unlocked.{$token}";
    }
}
