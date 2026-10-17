<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Support\Auth\EmailCode;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Signing in and signing up are one flow: type your email, get a 6-digit code, type it. A known address
// signs in, a new one gets an account. No passwords: whoever can read the inbox owns the account, which is
// also what "Forgot password?" amounted to. /login and /register show the same page with a different title.
class AuthenticatedSessionController extends Controller
{
    // Where the address a code went to is kept between "send" and "verify", so verify can't be pointed
    // at a different account than the one the code was sent for.
    private const CODE_EMAIL = 'auth.code_email';

    public function create(Request $request): Response
    {
        return self::page($request, 'login');
    }

    public function register(Request $request): Response
    {
        return self::page($request, 'register');
    }

    private static function page(Request $request, string $mode): Response
    {
        return Inertia::render('auth/email-code', [
            'mode' => $mode,
            'status' => $request->session()->get('status'),
            // "Try the demo" only shows once a demo family exists (DemoSeeder has run).
            'canDemo' => Family::where('is_demo', true)->exists(),
            // Set once a code is on its way: the page shows the code box for this address.
            'codeSentTo' => $request->session()->get(self::CODE_EMAIL),
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $email = EmailCode::normalize((string) $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email']);

        // Per address on top of the route's per-IP throttle, so nobody can flood one inbox from many IPs.
        $key = 'login-code-send:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => Translations::get('auth.code_too_many')]);
        }
        RateLimiter::hit($key, 600);

        EmailCode::send($email);
        $request->session()->put(self::CODE_EMAIL, $email);

        return back();
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $code = preg_replace('/\D/', '', (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code']);
        $email = $request->session()->get(self::CODE_EMAIL);

        if (! is_string($email) || ! EmailCode::check($email, $code)) {
            throw ValidationException::withMessages(['code' => Translations::get('auth.code_wrong')]);
        }
        $request->session()->forget(self::CODE_EMAIL);

        // Case-insensitive: older accounts may have been registered with capitals in the address.
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user) {
            $user->confirmEmailOwner();
            Auth::login($user, remember: true);

            return self::signedIn($request);
        }

        $user = User::forceCreate([
            'email' => $email,
            'email_verified_at' => now(),
            // The column stays NOT NULL; a random one nobody knows. Nothing checks passwords any more.
            'password' => Hash::make(Str::random(64)),
            // The language the page was shown in (browser's, via SetLocale) becomes theirs.
            'locale' => app()->getLocale(),
        ]);
        $request->session()->regenerate();

        return RegisteredUserController::registered($request, $user, 'email');
    }

    /**
     * Everything a successful sign-in does once the user is authenticated. Shared with "Sign in with
     * Google / Apple" (App\Support\Auth\SocialLogin) so both ways in land the same way.
     */
    public static function signedIn(Request $request): RedirectResponse
    {
        $request->session()->regenerate();

        // Establish the active family on login (registration does the same), so every
        // authenticated request has a family context for tenant scoping and modules.
        if ($family = $request->user()->families()->first()) {
            $request->session()->put('active_family_id', $family->id);
        }

        // Finish a pending invitation the user was logging in to accept.
        if ($token = $request->session()->get('invite_token')) {
            return redirect()->route('invite.show', $token);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
