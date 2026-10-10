<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Translations;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Linked "Sign in with Google / Apple" accounts (App\Support\Auth\SocialLogin).
            'socialAccounts' => $request->user()->socialAccounts()->oldest('id')->get(['id', 'provider', 'email']),
        ]);
    }

    /**
     * Unlink a Google / Apple sign-in. Only while another one stays connected.
     *
     * ponytail: we can't tell whether someone ever chose a password (a social sign-up gets a random one,
     * and the password column is NOT NULL), so the last linked provider can never be removed, even by
     * people who do have a password. Unlinking it could leave a social-only user with no way in but
     * "Forgot password?". Add a users.password_set_at (written by registration + both password forms) if
     * people ask to drop their only provider.
     */
    public function disconnectSocial(Request $request, int $id): RedirectResponse
    {
        $accounts = $request->user()->socialAccounts();
        $account = (clone $accounts)->findOrFail($id);

        if ($accounts->count() < 2) {
            return back()->withErrors(['social' => Translations::get('settings.social_last_method')]);
        }

        $account->delete();

        return back();
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back();
    }
}
