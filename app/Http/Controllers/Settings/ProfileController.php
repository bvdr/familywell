<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Auth\EmailCode;
use App\Support\Translations;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    private const PENDING_EMAIL = 'profile.pending_email';

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // A new address waiting for its code (confirmEmail): the page asks for the code.
            'pendingEmail' => $request->session()->get(self::PENDING_EMAIL),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $user->update(['name' => $data['name']]);

        // A new email only takes effect once its owner types the code sent to it (confirmEmail). The address
        // IS the account (sign-in is an email code), so an unproven change would let someone plant an account
        // under another person's address and get it handed over when that person signs up.
        // Security audit 2026-10-19: this used to save the new address straight away, unverified.
        if (EmailCode::normalize($data['email']) !== Str::lower($user->email)) {
            // Same cap as sign-in codes, per person, so this can't be used to flood someone's inbox.
            $key = 'email-change:'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages(['email' => Translations::get('auth.code_too_many')]);
            }
            RateLimiter::hit($key, 600);
            EmailCode::send($data['email']);
            $request->session()->put(self::PENDING_EMAIL, EmailCode::normalize($data['email']));
        }

        return to_route('profile.edit');
    }

    public function confirmEmail(Request $request): RedirectResponse
    {
        $code = preg_replace('/\D/', '', (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code']);
        $email = $request->session()->get(self::PENDING_EMAIL);

        if (! is_string($email) || ! EmailCode::check($email, $code)) {
            throw ValidationException::withMessages(['code' => Translations::get('auth.code_wrong')]);
        }
        // Someone may have taken the address between the code going out and coming back.
        if (User::whereRaw('lower(email) = ?', [$email])->whereKeyNot($request->user()->id)->exists()) {
            $request->session()->forget(self::PENDING_EMAIL);
            throw ValidationException::withMessages(['code' => Translations::get('settings.email_taken')]);
        }

        $request->user()->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        $request->session()->forget(self::PENDING_EMAIL);

        return to_route('profile.edit');
    }

    public function cancelEmail(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING_EMAIL);

        return to_route('profile.edit');
    }

    /** Settings > Delete account (design M8): what happens, then the code we email to confirm. */
    public function deleteAccount(): Response
    {
        return Inertia::render('settings/delete-account');
    }

    /**
     * Email the person a code to confirm deleting their account. A button, not on page load: the Me row
     * prefetches this page, so sending on GET would mail a code to everyone who just opens Me.
     */
    public function sendDeleteCode(Request $request): RedirectResponse
    {
        $user = $request->user();
        // Same cap as the email-change codes, so the button can't flood their inbox.
        $key = 'delete-account:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => Translations::get('auth.code_too_many')]);
        }
        RateLimiter::hit($key, 600);
        EmailCode::send($user->email);

        return back();
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // No password to re-enter: the 6-digit code we emailed (sendDeleteCode) is the "are you sure", and it
        // proves the person at the keyboard still owns the address, not just an open session on a shared device.
        $code = preg_replace('/\D/', '', (string) $request->input('code'));
        if (! EmailCode::check($user->email, $code)) {
            throw ValidationException::withMessages(['code' => Translations::get('auth.code_wrong')]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
