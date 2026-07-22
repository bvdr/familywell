<?php

namespace App\Support\Guests;

// A guest-shareable entity that can render its guest page (the view people reach through the link,
// after any password gate). Keeps the view logic in the module that owns the entity while the core
// PublicGuestController stays generic. Writes (e.g. a guest adding a spend) are entity-specific and
// live on the module's own public routes, authorized via GuestAccess.
interface ProvidesGuestView
{
    /** @return array{component: string, props: array<string, mixed>} Inertia component + props. */
    public function guestView(): array;
}
