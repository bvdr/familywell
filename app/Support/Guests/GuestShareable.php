<?php

namespace App\Support\Guests;

use App\Models\Guest;
use App\Models\GuestLink;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

// Opt-in guest sharing for any model: named, account-less participants reachable through one
// unguessable, optionally password-gated link. Pair with ProvidesGuestView to render the guest page,
// and register the type in GuestableRegistry from the module's service provider.
trait GuestShareable
{
    public function guests(): MorphMany
    {
        return $this->morphMany(Guest::class, 'guestable');
    }

    public function guestLink(): MorphOne
    {
        return $this->morphOne(GuestLink::class, 'guestable');
    }

    // Turn the link on (mint one if absent). Idempotent so "Copy link" stays stable.
    public function enableGuestLink(int $createdBy): GuestLink
    {
        return $this->guestLink()->firstOrCreate([], ['created_by' => $createdBy]);
    }

    // Turn the link off: deletes the link (and its password). Guests stay so re-enabling keeps them.
    public function disableGuestLink(): void
    {
        $this->guestLink()->delete();
        $this->unsetRelation('guestLink');
    }
}
