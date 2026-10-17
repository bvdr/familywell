<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Sign-up is just an email + code, so a new account has no name until "About you" asks. Anyone signed in
// without one is sent there first (and back to where they were going after), so the rest of the app can
// count on a name: member lists, avatars, "assigned to". Page loads only; API/JSON calls pass through.
class RequireName
{
    private const ALLOWED = ['onboarding.about', 'onboarding.about.store', 'logout', 'language.update', 'appearance.theme'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Inertia visits are XHRs (so expectsJson() is true) but they are page loads and follow redirects.
        if ($user && blank($user->name) && $request->isMethod('GET') && ($request->inertia() || ! $request->expectsJson())
            && ! $request->routeIs(...self::ALLOWED)) {
            return redirect()->guest(route('onboarding.about'));
        }

        return $next($request);
    }
}
