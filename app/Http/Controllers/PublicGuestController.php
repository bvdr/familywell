<?php

namespace App\Http\Controllers;

use App\Support\Guests\GuestAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

// The public guest page for a guest-shared entity. No auth: anyone with the token reaches it. If the
// link has a password, an unlock gate stands in front of the entity's own guest view. The token is a
// bearer secret; deleting the link revokes access.
class PublicGuestController extends Controller
{
    public function __construct(private GuestAccess $access) {}

    public function show(string $token)
    {
        $link = $this->access->link($token);

        // Password gate: render the unlock screen instead of the entity until the session clears it.
        if ($this->access->locked($link)) {
            return Inertia::render('guests/locked', ['token' => $token]);
        }

        $entity = $this->access->entity($link);
        $view = $entity->guestView();

        return Inertia::render($view['component'], $view['props']);
    }

    public function unlock(Request $request, string $token)
    {
        $link = $this->access->link($token);

        if (! $this->access->unlock($link, $request->input('password'))) {
            return back()->withErrors(['password' => 'Wrong password.']);
        }

        return redirect()->route('guest.public', $token);
    }
}
