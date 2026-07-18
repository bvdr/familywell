<?php

namespace App\Support\Sharing;

// A shareable entity that can render a read-only public page for its invite link. Keeps the public
// view logic in the module that owns the entity, while the core PublicShareController stays generic.
interface ProvidesPublicShareView
{
    /** @return array{component: string, props: array<string, mixed>} Inertia component + props for the read-only view. */
    public function publicShareView(): array;
}
