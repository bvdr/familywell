<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Mcp\McpTools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

// Settings > Connected apps: the MCP clients (Claude, ChatGPT, Cursor...) this person connected, and a
// way to cut one off. A "connection" is a live Passport access token: still valid, or expired but with a
// live refresh token the client will silently use (access tokens only last an hour). Refreshing revokes
// the previous access token, so each connection shows up once.
class ConnectedAppsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $families = $user->families()->pluck('families.name', 'families.id');

        $tokens = $this->live($user->id)->with(['client', 'refreshToken'])->latest()->get();

        return Inertia::render('settings/connected-apps', [
            'serverUrl' => config('mcp.server_url') ?: url('/mcp'),
            'apps' => $tokens->map(function (Token $t) use ($families) {
                $familyId = McpTools::familyFromScopes($t->scopes ?? []);

                return [
                    'id' => $t->id,
                    'name' => $t->client?->name ?? 'App',
                    // null when the person has since left that family (the token is refused anyway).
                    'family' => $familyId ? ($families[$familyId] ?? null) : null,
                    'access' => McpTools::accessFromScopes($t->scopes ?? []),
                    // Modules turned on since the person connected: access is fixed at consent, so these
                    // need a reconnect. Skipped for a family they left (nothing to reconnect to).
                    'missing' => $familyId && isset($families[$familyId]) ? McpTools::notConnected($familyId, $t->scopes ?? []) : [],
                    // The client registers when the person connects; the token row is renewed on refresh.
                    'connectedAt' => ($t->client?->created_at ?? $t->created_at)?->toIso8601String(),
                    'until' => ($t->refreshToken?->expires_at ?? $t->expires_at)?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        // Scoped to the current user: someone else's token id just 404s.
        $row = $this->live($request->user()->id)->whereKey($token)->firstOrFail();
        $row->revoke();
        RefreshToken::where('access_token_id', $row->id)->update(['revoked' => true]);

        return back();
    }

    /** Not revoked, and either still valid or renewable through a live refresh token. */
    private function live(int $userId)
    {
        return Token::query()->where('user_id', $userId)->where('revoked', false)
            ->where(fn ($q) => $q->where('expires_at', '>', now())
                ->orWhereHas('refreshToken', fn ($r) => $r->where('revoked', false)->where('expires_at', '>', now())));
    }
}
