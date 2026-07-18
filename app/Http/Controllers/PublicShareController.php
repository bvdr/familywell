<?php

namespace App\Http\Controllers;

use App\Models\ShareLink;
use App\Support\Sharing\ProvidesPublicShareView;
use Inertia\Inertia;

// Renders the read-only public page for a share link. No auth: anyone with the token can view.
// The token is a bearer secret; revoking the link (deleting the row) kills access.
class PublicShareController extends Controller
{
    public function show(string $token)
    {
        $link = ShareLink::where('token', $token)->firstOrFail();
        abort_if($link->isExpired(), 410, 'This link has expired.');

        $entity = $link->shareable;
        abort_unless($entity instanceof ProvidesPublicShareView, 404);

        $view = $entity->publicShareView();

        return Inertia::render($view['component'], $view['props']);
    }
}
