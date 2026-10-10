<?php

namespace App\Http\Middleware;

use App\Models\Family;
use App\Support\Billing\Billing;
use App\Support\Tenancy\CurrentFamily;
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

        // Paywall (hosted plan only): this gate already wraps every app + module route, so it is the one
        // place an unpaid family is held. Billing itself is under it too, so it must be let through.
        // Billing::enabled() first: self-hosted installs pay no extra query.
        if (Billing::enabled() && ! $request->routeIs('billing.*')
            && ($family = Family::find(CurrentFamily::id())) && ! Billing::hasAccess($family)) {
            return to_route('billing.show');
        }

        return $next($request);
    }
}
