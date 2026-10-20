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
        $locale = $request->user()?->locale;
        if (! $locale || ! Translations::supported($locale)) {
            // Symfony matches "fr-BE" to "fr" and returns the first locale (English) when nothing matches.
            // Norwegian: we ship Bokmål as `nb`, but many browsers send the macro code `no` (or Nynorsk `nn`), which
            // Symfony wouldn't match to `nb`. Offer those too and fold them into nb.
            $locale = $request->getPreferredLanguage([...array_keys(Translations::LOCALES), 'no', 'nn']);
            if (in_array($locale, ['no', 'nn'], true)) {
                $locale = 'nb';
            }
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
