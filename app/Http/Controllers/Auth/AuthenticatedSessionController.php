<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Family;
use App\Support\Auth\SocialLogin;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
            // "Try the demo" only shows once a demo family exists (DemoSeeder has run).
            'canDemo' => Family::where('is_demo', true)->exists(),
            // "Sign in with your password to connect Google/Apple": pre-fill the address it was for.
            'email' => SocialLogin::pendingLink($request)['email'] ?? null,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // A Google/Apple sign-in parked its link until this account's password was given (see
        // SocialLogin::signIn()); finish it now. A login as anyone else just drops it.
        if ($provider = SocialLogin::completePendingLink($request, $request->user())) {
            $request->session()->flash('status', str_replace(':provider', SocialLogin::label($provider), Translations::get('settings.social_connected')));
            // Show them where it now appears, unless they were on their way somewhere (OAuth consent...).
            $request->session()->put('url.intended', $request->session()->get('url.intended', route('password.edit', absolute: false)));
        }

        return self::signedIn($request);
    }

    /**
     * Everything a successful sign-in does once the user is authenticated. Shared with "Sign in with
     * Google / Apple" (App\Support\Auth\SocialLogin) so both ways in land the same way.
     */
    public static function signedIn(Request $request): RedirectResponse
    {
        $request->session()->regenerate();
        // Only a password login may complete a parked social link (store() above); any other way in drops it.
        $request->session()->forget(SocialLogin::PENDING);

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
