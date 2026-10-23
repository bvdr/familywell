<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminPush;
use App\Support\Analytics;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    /**
     * Everything that follows creating an account. Shared by the email code (AuthenticatedSessionController)
     * and "Sign in with Google / Apple" (App\Support\Auth\SocialLogin) so every sign-up gets the same welcome.
     *
     * @param  string  $method  'email' | 'google' | 'apple', for the signed_up analytics event
     */
    public static function registered(Request $request, User $user, string $method = 'email'): RedirectResponse
    {
        self::adoptAnalytics($request, $user);
        Analytics::track($user, 'signed_up', ['method' => $method], '/register');
        // signed_up (server) is the reliable count; signup_completed (browser) is the same step inside the
        // visitor's own Umami visit, so the familywell.org -> sign-up funnel can follow it.
        Analytics::queue('signup_completed', ['method' => $method]);
        // Every sign-up path (email code, Google, Apple) lands here, so this is the one "new account" admin push.
        // An email sign-up has no name yet (the About you step asks), so the address alone then.
        $who = $user->name ? "{$user->name} ({$user->email})" : $user->email;
        AdminPush::send('New account', "{$who} signed up".($method === 'email' ? '' : " with {$method}").'.'.self::sourceLine($user), "/users/{$user->id}");

        event(new Registered($user));

        // Remembered: with no password to type, signing in again means another code, so keep them signed in.
        Auth::login($user, remember: true);

        // Every new account starts with "About you" (name, language, colours); that step then finishes a
        // pending invitation, or goes on to the rest of onboarding (OnboardingController::saveAbout).
        return to_route('onboarding.about');
    }

    /**
     * " From reddit (social, launch)." for the admin push, from the first touch adoptAnalytics just saved.
     * Nothing when there is none (Umami off), so the push never claims a source we didn't see.
     */
    private static function sourceLine(User $user): string
    {
        $touch = $user->first_touch;
        if (! is_array($touch) || empty($touch['source'])) {
            return '';
        }
        // 'none' / 'unknown' mediums add nothing next to the source.
        $details = array_filter([
            in_array($touch['medium'] ?? null, ['none', 'unknown'], true) ? null : ($touch['medium'] ?? null),
            $touch['campaign'] ?? null,
        ]);

        return " From {$touch['source']}".($details ? ' ('.implode(', ', $details).')' : '').'.';
    }

    /**
     * Move what CaptureFirstTouch kept for this guest onto their new account, then forget it. The
     * familywell.org visitor id becomes their analytics_id (so their pre-signup visits are the same person
     * in Umami), unless another account already has it: e.g. a second sign-up from the same browser keeps
     * its own fresh id instead of merging two people into one.
     */
    private static function adoptAnalytics(Request $request, User $user): void
    {
        $analytics = (array) $request->session()->pull('analytics', []);

        $visitor = $analytics['visitor_id'] ?? null;
        if (is_string($visitor) && ! User::where('analytics_id', $visitor)->exists()) {
            $user->analytics_id = $visitor;
        }
        if (is_array($analytics['first_touch'] ?? null)) {
            $user->first_touch = $analytics['first_touch'];
        }
        if ($user->isDirty()) {
            $user->save();
        }
    }
}
