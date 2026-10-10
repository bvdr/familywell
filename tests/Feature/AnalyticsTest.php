<?php

use App\Models\User;
use App\Support\Analytics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// Per-user analytics (hosted familywell.co only): users.analytics_id + first_touch, the familywell.org
// hand-off (CaptureFirstTouch), adoption at sign-up, the identify prop and server-side Umami events.
// Social sign-up adoption is in Auth/SocialLoginTest.php, subscriptions in Billing/PaywallTest.php.

// ---------------------------------------------------------------- users.analytics_id

it('gives every new user a unique analytics id that cannot be mass-assigned', function () {
    [$a, $b] = User::factory()->count(2)->create();
    expect(Str::isUuid($a->analytics_id))->toBeTrue()
        ->and($a->analytics_id)->not->toBe($b->analytics_id);

    $c = User::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'secret123', 'analytics_id' => 'forced']);
    expect(Str::isUuid($c->fresh()->analytics_id))->toBeTrue();

    expect(fn () => DB::table('users')->where('id', $b->id)->update(['analytics_id' => $a->analytics_id]))
        ->toThrow(Illuminate\Database\QueryException::class); // unique index
});

it('backfills existing users when the migration runs', function () {
    $migration = require base_path('database/migrations/2026_10_10_150000_add_analytics_to_users.php');
    $migration->down();
    foreach (range(1, 3) as $i) {
        DB::table('users')->insert(['name' => "U{$i}", 'email' => "u{$i}@example.com", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    $migration->up();

    $ids = DB::table('users')->pluck('analytics_id');
    expect($ids)->toHaveCount(3)
        ->and($ids->every(fn ($id) => Str::isUuid($id)))->toBeTrue()
        ->and($ids->unique())->toHaveCount(3);
});

// ---------------------------------------------------------------- hand-off from familywell.org

it('keeps the familywell.org visitor id and first touch for a guest, first one wins', function () {
    umamiOn();
    $vid = (string) Str::uuid();

    get("/login?fw_vid={$vid}&fw_src=%20reddit%20&fw_med=social&fw_cmp=".str_repeat('x', 150))->assertOk();
    get('/login?fw_vid='.Str::uuid().'&fw_src=google&utm_source=newsletter')->assertOk();

    expect(session('analytics.visitor_id'))->toBe($vid)
        ->and(session('analytics.first_touch'))->toMatchArray([
            'source' => 'reddit', 'medium' => 'social', 'campaign' => str_repeat('x', 100), 'landing_path' => '/login',
        ]);
});

it('ignores a visitor id that is not a UUID', function () {
    umamiOn();
    get('/login?fw_vid=not-a-uuid')->assertOk();
    expect(session('analytics.visitor_id'))->toBeNull();
});

it('captures nothing for a signed-in user or without Umami configured', function () {
    $vid = (string) Str::uuid();
    get("/login?fw_vid={$vid}&fw_src=reddit")->assertOk(); // not configured
    expect(session('analytics'))->toBeNull();

    umamiOn();
    actingAs(User::factory()->create())->get("/settings/profile?fw_vid={$vid}&fw_src=reddit")->assertOk();
    expect(session('analytics'))->toBeNull();
});

it('falls back to the referrer and utm, upgrading only a direct first touch', function () {
    umamiOn();

    // Same-origin referrer = an internal hop: direct, and the referrer isn't kept.
    get('/login', ['Referer' => 'http://localhost/register'])->assertOk();
    expect(session('analytics.first_touch'))->toMatchArray(['source' => 'direct', 'medium' => 'none', 'referrer' => null]);

    // A real source later upgrades "direct"...
    get('/register', ['Referer' => 'https://www.google.com/search?q=family'])->assertOk();
    expect(session('analytics.first_touch'))->toMatchArray([
        'source' => 'google', 'medium' => 'organic', 'referrer' => 'https://www.google.com/search?q=family', 'landing_path' => '/register',
    ]);

    // ...and is then sticky.
    get('/login?utm_source=newsletter&utm_medium=email')->assertOk();
    expect(session('analytics.first_touch.source'))->toBe('google');
});

it('stores the route template, never a token from the landing URL', function () {
    umamiOn();
    get('/invite/secret-token-123?utm_source=whatsapp');
    expect(session('analytics.first_touch.landing_path'))->toBe('/invite/{token}');
});

// ---------------------------------------------------------------- sign-up adoption (password)

it('adopts the visitor id and first touch on password sign-up', function () {
    $events = umamiOn();
    $vid = (string) Str::uuid();
    get("/register?fw_vid={$vid}&fw_src=familywell.org&fw_med=referral&fw_cmp=launch")->assertOk();

    $this->post('/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    $user = User::where('email', 'ana@example.com')->sole();
    expect($user->analytics_id)->toBe($vid)
        ->and($user->first_touch)->toMatchArray(['source' => 'familywell.org', 'medium' => 'referral', 'campaign' => 'launch'])
        ->and(session('analytics'))->toBeNull()
        ->and($events())->toBe([['signed_up', ['method' => 'password'], $vid, '/register']]);
});

it('refuses a visitor id another account already has', function () {
    umamiOn();
    $vid = (string) Str::uuid();
    User::factory()->create()->forceFill(['analytics_id' => $vid])->save();
    get("/register?fw_vid={$vid}&fw_src=familywell.org")->assertOk();

    $this->post('/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    $user = User::where('email', 'ana@example.com')->sole();
    expect($user->analytics_id)->not->toBe($vid)
        ->and(Str::isUuid($user->analytics_id))->toBeTrue()
        ->and($user->first_touch['source'])->toBe('familywell.org');
});

// ---------------------------------------------------------------- identify prop

it('shares analyticsId only when Umami is configured and someone is signed in', function () {
    $user = User::factory()->create();

    get('/login')->assertInertia(fn ($page) => $page->where('analyticsId', null));
    actingAs($user)->get('/settings/profile')->assertInertia(fn ($page) => $page->where('analyticsId', null)
        ->missing('auth.user.analytics_id')->missing('auth.user.first_touch'));

    umamiOn();
    actingAs($user)->get('/settings/profile')->assertInertia(fn ($page) => $page->where('analyticsId', $user->analytics_id));
    auth()->logout();
    get('/login')->assertInertia(fn ($page) => $page->where('analyticsId', null));
});

// ---------------------------------------------------------------- server-side events

it('posts an event to Umami\'s collect API under the user\'s analytics id', function () {
    umamiOn();
    config(['app.url' => 'https://familywell.co']);

    Analytics::send('11111111-2222-3333-4444-555555555555', 'family_created', ['n' => 1], '/onboarding');

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://analytics.dragonpie.co/api/send'
        && $r->method() === 'POST'
        && $r->hasHeader('User-Agent', 'FamilyWell/1.0 (+https://familywell.co)')
        && $r->data() === ['type' => 'event', 'payload' => [
            'website' => 'site-123', 'hostname' => 'familywell.co', 'url' => '/onboarding', 'name' => 'family_created',
            'data' => ['n' => 1], 'id' => '11111111-2222-3333-4444-555555555555',
        ]]);
});

it('sends nothing when Umami is not configured', function () {
    Http::fake();
    Analytics::send('11111111-2222-3333-4444-555555555555', 'x');
    Analytics::track(User::factory()->create(), 'x');
    app()->terminate();

    Http::assertNothingSent();
});

it('swallows Umami failures', function () {
    umamiOn();
    Http::fake(fn () => throw new ConnectionException('Umami is down'));

    Analytics::send('11111111-2222-3333-4444-555555555555', 'x');
    expect(true)->toBeTrue(); // reached: nothing thrown
});

it('tracks family creation after the response', function () {
    $events = umamiOn();
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', ['name' => 'Alves', 'color' => '#2D5E3A', 'emoji' => '🌿'])->assertRedirect();

    expect($events())->toBe([['family_created', [], $user->analytics_id, '/onboarding']]);
});

it('tracks assistant connections and every MCP tool call', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $events = umamiOn();

    $token = mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'];
    mcpTool($token, 'get_family');

    expect($events())->toBe([
        ['assistant_connected', ['modules' => 1], $users[0]->analytics_id, '/oauth'],
        ['assistant_tool', ['tool' => 'get_family'], $users[0]->analytics_id, '/mcp'],
    ]);
});
