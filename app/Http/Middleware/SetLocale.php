<?php

namespace App\Http\Middleware;

use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;

// Applies the signed-in user's language preference for the request, so both server-rendered strings
// (__()) and the translations shared to the client use their locale. Without one (guests on the login
// and sign-up pages, or an account that never picked a language) the browser's Accept-Language decides,
// so a visitor is welcomed in their own language; sign-up then saves that locale as theirs.
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        // A guest who picked a language on the sign-in page (AuthenticatedSessionController::locale) beats the browser's.
        $locale = $request->user()?->locale ?? ($request->hasSession() ? $request->session()->get('locale') : null);
        if (! $locale || ! Translations::supported($locale)) {
            $locale = Translations::fromBrowser($request);
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
