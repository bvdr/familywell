<?php

namespace App\Http\Middleware;

use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;

// Applies the signed-in user's language preference for the request, so both server-rendered strings
// (__()) and the translations shared to the client use their locale. Falls back to the app default.
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->user()?->locale;
        if ($locale && Translations::supported($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
