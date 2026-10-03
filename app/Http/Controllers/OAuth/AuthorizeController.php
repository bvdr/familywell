<?php

namespace App\Http\Controllers\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;

// GET /oauth/authorize. Passport's controller, except consent is ALWAYS shown. Passport skips it when
// the user already holds a token for the client with the requested scopes, but MCP clients only ever
// request `mcp:use`, so a second connection would silently reuse the first family + module choices.
// Every connection must pick its family and permissions, so we never consider scopes "already granted".
class AuthorizeController extends AuthorizationController
{
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
