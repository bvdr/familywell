<?php

use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

// The module-requests board (familywell.org) signs people in through our OAuth server as a first-party
// client: no consent screen, and only for the `board:identity` scope (see config/board.php).
// Helpers boardClient() / boardAuthorizeQuery() live in tests/Pest.php.

it('skips consent for a board client asking only for board:identity', function () {
    $client = boardClient();
    $user = User::factory()->create();
    [$query] = boardAuthorizeQuery($client);

    $location = actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://familywell.org/api/auth/callback?')
        ->and($location)->toContain('code=')
        ->and($location)->toContain('state=xyz');
});

it('shows consent when a board client asks for more than board:identity', function () {
    [, $users] = mcpFamily(['tasks']);
    $client = boardClient();
    [$query] = boardAuthorizeQuery($client, 'board:identity mcp:use');

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();
});

it('never skips consent for a client that is not a board client', function () {
    $client = boardClient();
    config(['board.client_ids' => []]);
    $user = User::factory()->create();
    [$query] = boardAuthorizeQuery($client);

    actingAs($user)->get("/oauth/authorize?{$query}")->assertOk();
});

it('creates a board client from the command', function () {
    mcpKeys();
    $this->artisan('board:client', ['name' => 'FamilyWell board', 'redirect' => 'https://familywell.org/api/auth/callback'])
        ->expectsOutputToContain('Client ID:')
        ->expectsOutputToContain('Client secret:')
        ->assertExitCode(0);

    expect(OAuthClient::where('name', 'FamilyWell board')->first()->redirect_uris)
        ->toBe(['https://familywell.org/api/auth/callback']);
});

it('sends unverified people through email verification, once per session', function () {
    Notification::fake();
    $client = boardClient();
    $user = User::factory()->unverified()->create();
    [$query] = boardAuthorizeQuery($client);

    actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect(route('verification.notice'));
    expect(session('url.intended'))->toContain('/oauth/authorize?');
    actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect(route('verification.notice'));

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('does not ask MCP clients for verification', function () {
    Notification::fake();
    [$family, $users] = mcpFamily(['tasks']);
    $users[0]->forceFill(['email_verified_at' => null])->save();
    [$query] = mcpAuthorizeQuery(mcpClient());

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();
    Notification::assertNothingSent();
});
