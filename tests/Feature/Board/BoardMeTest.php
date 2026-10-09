<?php

use App\Models\Family;
use App\Models\User;

// GET /api/board/me: the identity call the board makes after sign-in. boardToken() lives in tests/Pest.php.

it('returns the user, the verified flag and their families sorted by name', function () {
    $client = boardClient();
    $user = User::factory()->create();
    foreach (['Zeta house', 'Alves'] as $name) {
        Family::factory()->create(['name' => $name, 'owner_id' => $user->id])->members()->attach($user, ['role' => 'admin']);
    }
    $token = boardToken($user, $client)['access_token'];
    auth()->forgetGuards();

    $this->withToken($token)->getJson('/api/board/me')->assertOk()->assertExactJson([
        'user_id' => $user->id,
        'email_verified' => true,
        'families' => [
            ['id' => Family::where('name', 'Alves')->value('id'), 'name' => 'Alves'],
            ['id' => Family::where('name', 'Zeta house')->value('id'), 'name' => 'Zeta house'],
        ],
    ]);
});

it('refuses MCP tokens', function () {
    boardClient();
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'];
    auth()->forgetGuards();

    $this->withToken($token)->getJson('/api/board/me')->assertForbidden();
});

it('refuses a board token once its client is no longer a board client', function () {
    $client = boardClient();
    $token = boardToken(User::factory()->create(), $client)['access_token'];
    config(['board.client_ids' => []]);
    auth()->forgetGuards();

    $this->withToken($token)->getJson('/api/board/me')->assertForbidden();
});

it('needs a token', function () {
    mcpKeys(); // the api guard builds Passport's resource server, which needs the signing keys even for a bare request
    $this->getJson('/api/board/me')->assertUnauthorized();
});
