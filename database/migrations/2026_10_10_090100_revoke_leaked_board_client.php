<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Incident 2026-10-10: the first board client's secret was exposed in a chat during setup, and the client
// was issued an MCP-scoped token by mistake. After the first_party migration it would be an ordinary,
// active client again (first_party=false), so the old Worker would send users to the MCP consent screen.
// Revoke it and everything it issued. No-op where the client does not exist (CI/dev databases).
return new class extends Migration
{
    private const CLIENT_ID = '01a1240b-c17d-7051-94fd-599e6bd771d9';

    public function up(): void
    {
        $db = DB::connection(config('passport.connection'));

        if (! $db->table('oauth_clients')->where('id', self::CLIENT_ID)->exists()) {
            return;
        }

        $db->transaction(function () use ($db) {
            $db->table('oauth_clients')->where('id', self::CLIENT_ID)->update(['revoked' => true]);
            $tokenIds = $db->table('oauth_access_tokens')->where('client_id', self::CLIENT_ID);
            $db->table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds->clone()->select('id'))->update(['revoked' => true]);
            $tokenIds->update(['revoked' => true]);
            $db->table('oauth_auth_codes')->where('client_id', self::CLIENT_ID)->delete();
        });
    }

    // Re-enabling a leaked client is never wanted, so there is nothing to undo.
    public function down(): void {}

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
