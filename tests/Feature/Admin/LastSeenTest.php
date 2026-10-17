<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

// users.last_seen_at / last_seen_via: stamped by TrackLastSeen on web requests and MCP calls, at most once
// per 5 minutes, and shown by the admin API as lastActiveAt / lastActiveVia.

it('stamps web use, then not again within 5 minutes', function () {
    $user = User::factory()->create();
    Carbon::setTestNow('2026-10-10 10:00:00');
    actingAs($user)->get('/settings/profile');
    expect($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-10 10:00:00')
        ->and($user->fresh()->last_seen_via)->toBe('web');

    Carbon::setTestNow('2026-10-10 10:03:00');
    actingAs($user->fresh())->get('/settings/profile');
    expect($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-10 10:00:00');

    Carbon::setTestNow('2026-10-10 10:06:00');
    actingAs($user->fresh())->get('/settings/profile');
    expect($user->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-10 10:06:00');
});

it('does not touch updated_at', function () {
    $user = User::factory()->create(['updated_at' => '2026-01-01 00:00:00']);
    actingAs($user)->get('/settings/profile');
    expect($user->fresh()->updated_at->toDateTimeString())->toBe('2026-01-01 00:00:00');
});

it('stamps MCP use as mcp', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'];
    auth()->forgetGuards();
    DB::table('users')->where('id', $users[0]->id)->update(['last_seen_at' => null, 'last_seen_via' => null]);

    mcpCall($token, 'tools/list')->assertOk();

    expect($users[0]->fresh()->last_seen_via)->toBe('mcp')
        ->and($users[0]->fresh()->last_seen_at)->not->toBeNull();
});

it('shows lastActiveAt and lastActiveVia in the admin API', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = boardToken($admin, adminClient(), App\Models\OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();
    $ana = User::factory()->create(['name' => 'Ana']);
    DB::table('users')->where('id', $ana->id)->update(['last_seen_at' => '2026-10-09 08:00:00', 'last_seen_via' => 'mcp']);

    $item = $this->withToken($token)->getJson("/api/admin/users/{$ana->id}")->assertOk()->json();
    expect($item['lastActiveAt'])->toStartWith('2026-10-09T08:00:00')
        ->and($item['lastActiveVia'])->toBe('mcp');
});

it('keeps the web country from Cloudflare, updates it when it changes, and ignores unknown/Tor', function () {
    $user = User::factory()->create();
    Carbon::setTestNow('2026-10-10 10:00:00');

    actingAs($user)->get('/settings/profile'); // no header (local / self-hosted): nothing
    expect($user->fresh()->country)->toBeNull();

    actingAs($user->fresh())->get('/settings/profile', ['CF-IPCountry' => 'ro']);
    expect($user->fresh()->country)->toBe('RO');

    // Within the 5 minutes, but the country changed: written anyway.
    Carbon::setTestNow('2026-10-10 10:01:00');
    actingAs($user->fresh())->get('/settings/profile', ['CF-IPCountry' => 'DE']);
    expect($user->fresh()->country)->toBe('DE');

    Carbon::setTestNow('2026-10-10 10:10:00');
    foreach (['XX', 'T1', 'ROU', ''] as $junk) {
        actingAs($user->fresh())->get('/settings/profile', ['CF-IPCountry' => $junk]);
    }
    expect($user->fresh()->country)->toBe('DE');
});

it('never takes the country from MCP calls (the assistant\'s servers, not the person)', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'];
    auth()->forgetGuards();
    DB::table('users')->where('id', $users[0]->id)->update(['country' => null, 'last_seen_at' => null]);

    test()->withHeader('CF-IPCountry', 'US'); // kept by mcpCall's withHeaders()
    mcpCall($token, 'tools/list')->assertOk();

    expect($users[0]->fresh()->country)->toBeNull();
});

it('shows the country in the admin API list and detail', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = boardToken($admin, adminClient(), App\Models\OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();
    $ana = User::factory()->create(['name' => 'Ana']);
    DB::table('users')->where('id', $ana->id)->update(['country' => 'RO']);

    expect($this->withToken($token)->getJson("/api/admin/users/{$ana->id}")->assertOk()->json('country'))->toBe('RO');
    expect(collect($this->withToken($token)->getJson('/api/admin/users')->assertOk()->json('items'))->firstWhere('id', $ana->id)['country'])->toBe('RO');
});
