<?php

namespace Modules\Vehicles;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Every Vehicles page gets `carCurrency`: the currency car costs are stored in (VehicleExpenses::currency,
 * the family's "Vehicles" spend list). The pages used to hard-code "€" (vehicles.cost_value), so a RON
 * family saw its 1,072.29 lei oil change as €1,072.29. Shared from this route group's middleware, per
 * request (never from a provider's boot(): under Octane that share is dropped after the first request).
 * Lazy, so a page that doesn't render it costs no query.
 */
class ShareCarCurrency
{
    public function handle(Request $request, Closure $next)
    {
        Inertia::share('carCurrency', fn () => VehicleExpenses::currency((int) CurrentFamily::id()));

        return $next($request);
    }
}
