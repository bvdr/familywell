<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

// Passport's client plus one rule: the board's own clients (config('board.client_ids')) skip the consent
// screen, but only when they ask for exactly `board:identity`. Asking for anything more (an MCP scope, a
// family) gets the normal consent screen, so a leaked board secret can never mint MCP access silently.
// Registered with Passport::useClientModel() in AppServiceProvider.
class OAuthClient extends Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $ids = array_map(fn ($scope) => $scope->id, $scopes);

        return self::isBoardClient((string) $this->getKey()) && $ids === ['board:identity'];
    }

    public static function isBoardClient(?string $clientId): bool
    {
        return $clientId !== null && $clientId !== '' && in_array($clientId, config('board.client_ids'), true);
    }
}
