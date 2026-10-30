<?php

namespace App\Http\Middleware;

use App\Models\Family;
use App\Support\Region;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Families made before families.timezone existed have none (FamilyClock then uses UTC). app.tsx writes the browser's
 * zone into the `fw_tz` cookie on every page load, so the first request an ADMIN of such a family makes after that
 * stores it: no extra endpoint or round-trip, and it's idempotent (only while the column is null; a pick in Settings
 * › Region & units is never overwritten). Members' browsers don't decide the family's zone.
 *
 * Cost: one primary-key read per request that carries the cookie, memoized in FamilyClock for the rest of the request
 * (Home and the shared `timezone` prop read the same value anyway).
 */
class FillFamilyTimezone
{
    public function handle(Request $request, Closure $next)
    {
        $zone = $request->cookie('fw_tz');
        $familyId = CurrentFamily::id();
        if (is_string($zone) && $familyId && FamilyClock::stored($familyId) === null && ($user = $request->user())) {
            $family = Family::find($familyId);
            if ($family && $user->can('manageMembers', $family)
                && Validator::make(['timezone' => $zone], ['timezone' => Region::TIMEZONE_RULE])->passes()) {
                $family->forceFill(['timezone' => $zone])->save();
            }
        }

        return $next($request);
    }
}
