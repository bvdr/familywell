<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\SocialLogin;
use App\Support\Auth\SocialLoginException;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

// "Continue with Google / Apple" (see App\Support\Auth\SocialLogin for the protocol details).
class SocialLoginController extends Controller
{
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        // An install without the provider's keys has no button, and no working route either.
        abort_unless(SocialLogin::configured($provider), 404);

        return $provider === 'apple' ? SocialLogin::appleRedirect($request) : SocialLogin::googleRedirect($request);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(SocialLogin::configured($provider), 404);

        try {
            $identity = $provider === 'apple' ? SocialLogin::appleIdentity($request) : SocialLogin::googleIdentity($request);

            return SocialLogin::signIn($request, $provider, $identity);
        } catch (SocialLoginException $e) {
            $key = $e->getMessage();
        } catch (Throwable $e) {
            // The provider API failed or answered oddly: worth knowing about, but the person just gets
            // the generic "try again" on the login page, never a 500.
            report($e);
            $key = 'auth.social_failed';
        }

        return to_route('login')->withErrors(['social' => Translations::get($key)]);
    }
}
