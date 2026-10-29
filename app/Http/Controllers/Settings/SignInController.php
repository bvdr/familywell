<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Auth\SocialLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Settings > Sign-in: the Google / Apple accounts linked for one-tap sign-in. There are no passwords;
// the email code always works, so any of them can be disconnected, the last one included.
class SignInController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/sign-in', [
            // Linked "Sign in with Google / Apple" accounts (App\Support\Auth\SocialLogin).
            'socialAccounts' => $request->user()->socialAccounts()->oldest('id')->get(['id', 'provider', 'email']),
            // Providers this install has keys for, so the page can list the ones not linked yet.
            'providers' => SocialLogin::enabled(),
        ]);
    }

    public function disconnectSocial(Request $request, int $id): RedirectResponse
    {
        $request->user()->socialAccounts()->findOrFail($id)->delete();

        return back();
    }
}
