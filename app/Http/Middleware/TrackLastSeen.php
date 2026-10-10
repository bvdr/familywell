<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// Stamps users.last_seen_at / last_seen_via ("web" or "mcp") for the admin's "last active". At most one
// write per user per 5 minutes: the already-loaded user model is checked first, so a busy session costs no
// extra query. A plain query-builder UPDATE, so it doesn't touch updated_at or fire model events.
class TrackLastSeen
{
    private const EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next, string $via = 'web'): Response
    {
        $response = $next($request);

        $user = $request->user();
        $seen = $user?->last_seen_at;
        if ($user && (! $seen || $seen->lt(now()->subSeconds(self::EVERY_SECONDS)) || $user->last_seen_via !== $via)) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now(), 'last_seen_via' => $via]);
        }

        return $response;
    }
}
