<?php

namespace App\Http\Middleware;

use App\Models\OAuthClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Every /api/admin call: the token has `admin`, it came from the platform-admin client, and the user is a
// platform admin right now. auth:api loads the user fresh per request, so admin:revoke bites at once.
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $ok = $user && $user->is_platform_admin && $user->tokenCan('admin')
            && OAuthClient::isPlatformAdminClient((string) $user->token()?->oauth_client_id);

        return $ok ? $next($request) : response()->json(['error' => 'forbidden'], 403);
    }
}
