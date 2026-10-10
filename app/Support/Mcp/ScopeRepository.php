<?php

namespace App\Support\Mcp;

use App\Models\OAuthClient;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\ScopeRepository as PassportScopeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

// Passport's scope repository, taught two things for MCP tokens:
//  1. `family:<id>` is a valid scope. It is dynamic (one per family), so it cannot sit in the static
//     Passport::tokensCan() list. It is how a token is bound to exactly one family; Passport stores it on
//     the token and copies it onto refreshed tokens, so the binding needs no extra table.
//  2. The client's own scope list is ignored. Dynamically registered MCP clients only ever declare
//     `mcp:use`; what a token may do is decided on our consent screen (ApproveController sets the scopes),
//     so filtering by client-declared scopes would only strip the user's choices.
//  3. `admin` is only ever finalised for a platform-admin client (OAuthClient::isPlatformAdminClient), whatever was asked for.
// Bound in place of Passport's bridge class in AppServiceProvider.
class ScopeRepository extends PassportScopeRepository
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return self::known($identifier) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        $adminClient = OAuthClient::isPlatformAdminClient($clientEntity->getIdentifier());

        return collect($scopes)
            ->filter(fn (ScopeEntityInterface $scope): bool => self::known($scope->getIdentifier())
                && ($scope->getIdentifier() !== 'admin' || $adminClient))
            ->values()
            ->all();
    }

    // '*' is never known: Passport::hasScope('*') is true, but a wildcard would bypass every module gate.
    private static function known(string $identifier): bool
    {
        return $identifier !== '*' && (Passport::hasScope($identifier) || preg_match('/^family:\d+$/', $identifier) === 1);
    }
}
