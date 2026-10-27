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

        // Mid-onboarding (step 1 done, the rest not): back to the step they're on. GET only, so nothing a page
        // posts in the meantime is lost. This is also how opening the app from the Home Screen resumes it.
        // The tour is the exception: it runs on the dashboard itself (the shared `tour` prop), so only other pages
        // send them back there.
        // Billing stays reachable from the plan step (pay or look at the plans), the same as for a locked family.
        if (($step = $request->user()->onboarding_step) && $request->isMethod('GET') && ! ($step === 'plan' && $request->routeIs('billing.*'))) {
            if ($step === 'tour') {
                return $request->routeIs('dashboard') ? $next($request) : to_route('dashboard');
            }

            return to_route("onboarding.{$step}");
        }

        // Paywall (hosted plan only): this gate already wraps every app + module route, so it is the one
        // place an unpaid family is held. Billing itself is under it too, so it must be let through.
        // Billing::enabled() first: self-hosted installs pay no extra query.
        if (Billing::enabled() && ! $request->routeIs('billing.*')
            && ($family = Family::find(CurrentFamily::id())) && ! Billing::hasAccess($family)) {
            // A hard-paywall family goes back to the plan step of the onboarding (an admin can pay there; step 'plan'
            // is what lets that step open). A member who can't pay, or a soft family, gets the Billing page.
            if ($family->hard_paywall && $request->user()->can('manageBilling', $family)) {
                $request->user()->forceFill(['onboarding_step' => 'plan'])->save();

                return to_route('onboarding.plan');
            }

            return to_route('billing.show');
        }

        return $next($request);
    }
}
