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
