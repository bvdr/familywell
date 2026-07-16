<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;

class SetActiveFamily
{
    public function handle(Request $request, Closure $next)
    {
        // Read the session value directly. It is only ever set for an authenticated user
        // (register/login), and this middleware runs before the route `auth` middleware where
        // $request->user() may not be resolved yet. Gating on $request->user() here would drop
        // the family context for a logged-in user whose user() has not resolved.
        $familyId = $request->session()->get('active_family_id');

        if (! $familyId && ($user = $request->user())) {
            $familyId = $user->families()->first()?->id;
        }

        if ($familyId) {
            CurrentFamily::set((int) $familyId);
            $request->session()->put('active_family_id', $familyId);
        } else {
            CurrentFamily::forget(); // never inherit a prior request's context under Octane
        }

        return $next($request);
    }
}
