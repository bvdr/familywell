<?php

namespace App\Support\Mcp;

use Laravel\Mcp\Server\Registrar;

/**
 * laravel/mcp's Registrar with one change: the OAuth Protected Resource Metadata (RFC 9728) also says
 * how the bearer token is sent. Both /.well-known/oauth-protected-resource and its /{path} variant
 * (e.g. /mcp) are built by protectedResourceMetadata() via late static binding, so overriding it here
 * fixes both. Bound over the package's singleton in AppServiceProvider.
 */
class McpRegistrar extends Registrar
{
    protected static function protectedResourceMetadata(string $path): array
    {
        return parent::protectedResourceMetadata($path) + [
            // Only "header": /mcp authenticates with Passport's TokenGuard (auth:api), which reads
            // `Authorization: Bearer` and nothing from the query string or body (RFC 6750 §2.2/§2.3).
            'bearer_methods_supported' => ['header'],
        ];
    }
}
