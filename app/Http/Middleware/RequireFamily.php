<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// The app is meaningless without a family (every module is tenant-scoped). This gate holds a
// signed-in but family-less user in onboarding until they create or join one. Applied to the app
// route group only; the onboarding routes themselves must NOT use it, or it would loop.
class RequireFamily
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()->families()->exists()) {
            return to_route('onboarding.index');
        }

        return $next($request);
    }
}
