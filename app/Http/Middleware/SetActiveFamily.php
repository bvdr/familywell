<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;

class SetActiveFamily
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $familyId = $user
            ? ($request->session()->get('active_family_id') ?? $user->families()->first()?->id)
            : null;

        if ($familyId) {
            CurrentFamily::set($familyId);
            $request->session()->put('active_family_id', $familyId);
        } else {
            CurrentFamily::forget(); // never inherit a prior request's context under Octane
        }

        return $next($request);
    }
}
