<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareRealtime
{
    public function handle(Request $request, Closure $next)
    {
        // Reverb connection config for the browser, resolved per request (Octane-safe).
        // Public host/port/scheme may differ from the server-to-server values.
        View::share('reverb', [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => env('REVERB_PUBLIC_HOST', env('REVERB_HOST', 'localhost')),
            'port' => (int) env('REVERB_PUBLIC_PORT', env('REVERB_PORT', 8080)),
            'scheme' => env('REVERB_PUBLIC_SCHEME', env('REVERB_SCHEME', 'http')),
        ]);
        View::share('activeFamilyId', CurrentFamily::id());

        return $next($request);
    }
}
