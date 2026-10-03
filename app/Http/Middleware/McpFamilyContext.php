<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpTools;
use App\Support\Tenancy\CurrentFamily;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Runs after auth:api on /mcp. A token is bound to exactly one family through its `family:<id>` scope
// (picked on the consent screen); this sets that family as the request's tenant so every
// BelongsToFamily query behaves as in the web UI. No session is involved.
class McpFamilyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Only a real bearer token has oauth_scopes (a cookie TransientToken has none -> refused).
        $familyId = McpTools::familyFromScopes((array) ($user?->token()?->oauth_scopes ?? []));
        if (! $familyId) {
            return response()->json(['error' => 'This token is not bound to a family. Reconnect the app.'], 403);
        }

        // Leaving a family kills its connectors: membership is re-checked on every request.
        if (! $user->families()->whereKey($familyId)->exists()) {
            return response()->json(['error' => 'You are no longer a member of this family. Reconnect the app.'], 403);
        }

        CurrentFamily::set($familyId);

        return $next($request);
    }
}
