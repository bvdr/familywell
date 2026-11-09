<?php

namespace App\Support\Mcp;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as PassportClientRepository;

/**
 * Passport's client lookup, except an id that isn't a UUID is simply "no such client". Client ids are UUIDs, and
 * Postgres throws on comparing a uuid column with anything else, so `client_id=nope` on /oauth/authorize or
 * /oauth/token was a 500 "Something broke" page instead of the normal OAuth invalid_client error (security
 * report SEC-IQNGL, 2026-11-08). Every Passport path (authorize, token, approve) goes through find().
 */
class ClientRepository extends PassportClientRepository
{
    public function find(string|int $id): ?Client
    {
        return Str::isUuid((string) $id) ? parent::find($id) : null;
    }
}
