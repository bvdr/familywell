<?php

use App\Models\OAuthClient;
use Illuminate\Support\Facades\DB;

// The data migration that revokes the board client whose secret leaked (incident 2026-10-10).
const LEAKED_BOARD_CLIENT = '01a1240b-c17d-7051-94fd-599e6bd771d9';

function runRevokeMigration(): void
{
    (require base_path('database/migrations/2026_10_10_090100_revoke_leaked_board_client.php'))->up();
}

it('revokes the leaked client, its tokens and refresh tokens, and drops its auth codes', function () {
    mcpKeys();
    $client = boardClient();
    $client->forceFill(['id' => LEAKED_BOARD_CLIENT])->saveQuietly();
    $user = App\Models\User::factory()->create();
    $now = now();
    DB::table('oauth_access_tokens')->insert(['id' => 'tok1', 'user_id' => $user->id, 'client_id' => LEAKED_BOARD_CLIENT, 'revoked' => false, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('oauth_refresh_tokens')->insert(['id' => 'ref1', 'access_token_id' => 'tok1', 'revoked' => false]);
    DB::table('oauth_auth_codes')->insert(['id' => 'code1', 'user_id' => $user->id, 'client_id' => LEAKED_BOARD_CLIENT, 'revoked' => false]);

    runRevokeMigration();

    expect((bool) DB::table('oauth_clients')->where('id', LEAKED_BOARD_CLIENT)->value('revoked'))->toBeTrue()
        ->and((bool) DB::table('oauth_access_tokens')->where('id', 'tok1')->value('revoked'))->toBeTrue()
        ->and((bool) DB::table('oauth_refresh_tokens')->where('id', 'ref1')->value('revoked'))->toBeTrue()
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('does nothing when the client does not exist', function () {
    mcpKeys();
    $other = boardClient();

    runRevokeMigration();

    expect(OAuthClient::isBoardClient($other->getKey()))->toBeTrue();
});
