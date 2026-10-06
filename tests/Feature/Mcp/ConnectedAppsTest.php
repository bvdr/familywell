<?php

use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;

it('lists my connected apps with their family and permissions, not other people\'s', function () {
    [$family, $users] = mcpFamily(['tasks', 'notes'], 2);
    mcpConnect($users[0], $family->id, ['tasks' => 'write', 'notes' => 'read']);
    mcpConnect($users[1], $family->id, ['tasks' => 'read']);

    actingAs($users[0])->get('/settings/connected-apps')->assertOk()->assertInertia(fn ($p) => $p
        ->component('settings/connected-apps')
        ->has('apps', 1)
        ->where('apps.0.name', 'Claude')
        ->where('apps.0.family', $family->name)
        ->where('apps.0.access', ['tasks' => 'write', 'notes' => 'read'])
        ->where('apps.0.missing', [])
        ->where('serverUrl', url('/mcp')));
});

it('keeps listing a connection whose access token expired but can still refresh', function () {
    [$family, $users] = mcpFamily(['tasks']);
    mcpConnect($users[0], $family->id, ['tasks' => 'read']);

    $this->travel(2)->hours(); // access token (1h) expired, refresh token (30d) still good
    actingAs($users[0])->get('/settings/connected-apps')->assertInertia(fn ($p) => $p->has('apps', 1));
});

it('disconnects an app: its token and refresh token stop working at once', function () {
    [$family, $users] = mcpFamily(['tasks'], 2);
    $tokens = mcpConnect($users[0], $family->id, ['tasks' => 'read']);
    $id = Token::first()->id;

    // Someone else cannot revoke it.
    actingAs($users[1])->delete("/settings/connected-apps/{$id}")->assertNotFound();

    actingAs($users[0])->delete("/settings/connected-apps/{$id}")->assertRedirect();
    expect(Token::find($id)->revoked)->toBeTrue()
        ->and(RefreshToken::where('access_token_id', $id)->value('revoked'))->toBeTrue();

    mcpCall($tokens['access_token'], 'tools/list')->assertUnauthorized();
    actingAs($users[0])->get('/settings/connected-apps')->assertInertia(fn ($p) => $p->has('apps', 0));
});

it('flags modules turned on after the app was connected, since those need a reconnect', function () {
    [$family, $users] = mcpFamily(['tasks', 'notes']);
    mcpConnect($users[0], $family->id, ['tasks' => 'read']);

    actingAs($users[0])->get('/settings/connected-apps')->assertInertia(fn ($p) => $p->where('apps.0.missing', ['notes']));
});
