<?php

use App\Models\Family;
use App\Models\OAuthClient;
use App\Models\User;
use App\Support\Billing\Billing;
use App\Support\Engagement;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\DB;
use Modules\Notes\Folder;
use Modules\Notes\Note;

use function Pest\Laravel\actingAs;

// The paywall waits for real use: an unpaid family keeps full access until it has added items in
// Engagement::MODULES_NEEDED (3) modules AND is Billing::GRACE_HOURS (24) old. No Stripe trial on top.

function freeFamily(): array
{
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    return [$user, $family];
}

it('keeps a new unpaid family in until 3 modules AND 24 hours, then shows the paywall', function () {
    [$user, $family] = freeFamily();
    actingAs($user)->get('/dashboard')->assertOk(); // brand new: no paywall

    foreach (['shopping', 'tasks', 'spending'] as $module) {
        Engagement::record($family->id, $module);
    }
    expect($family->fresh()->engaged_at)->not->toBeNull();
    actingAs($user)->get('/dashboard')->assertOk(); // 3 modules, but not 24h old yet

    $this->travel(25)->hours();
    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.plan'));
    // The plan selection opens for them (no onboarding step needed), not the old Billing page.
    actingAs($user)->get('/onboarding/plan')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/plan'));
});

it('shows the paywall the moment an older family adds its 3rd module, not before', function () {
    [$user, $family] = freeFamily();
    $family->forceFill(['created_at' => now()->subDays(5)])->save();

    Engagement::record($family->id, 'notes');
    Engagement::record($family->id, 'notes'); // the same module twice is still one
    Engagement::record($family->id, 'recipes');
    actingAs($user)->get('/dashboard')->assertOk();

    Engagement::record($family->id, 'calendar');
    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.plan'));
});

it('counts the items people add (any path), not the containers the app or they set up', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    [$user, $family] = freeFamily();
    CurrentFamily::set($family->id);

    Folder::create(['name' => 'Recipes', 'created_by' => $user->id]); // a container: doesn't count
    expect(DB::table('family_module_activity')->count())->toBe(0);

    Note::create(['title' => 'Groceries', 'body' => 'milk', 'created_by' => $user->id]);
    expect(DB::table('family_module_activity')->where('family_id', $family->id)->pluck('module_id')->all())->toBe(['notes']);
});

it('rebuilds activity for existing families from the items they already have', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    [$user, $family] = freeFamily();
    CurrentFamily::set($family->id);
    Note::create(['title' => 'Old note', 'created_by' => $user->id]);
    DB::table('family_module_activity')->delete();

    Engagement::backfill();
    Engagement::backfill(); // idempotent

    expect(DB::table('family_module_activity')->where('family_id', $family->id)->count())->toBe(1)
        ->and($family->fresh()->engaged_at)->toBeNull(); // one module: still free
});

it('lets a free-period family open the plans and go back; a paid one sees its plan', function () {
    [$user, $family] = freeFamily();

    actingAs($user)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('subscribed', false)->where('locked', false));

    pastFreePeriod($family);
    actingAs($user)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('subscribed', false)->where('locked', true));

    $family->forceFill(['subscription_status' => 'active'])->save();
    actingAs($user)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('subscribed', true));
    expect(Billing::hasAccess($family->fresh()))->toBeTrue();
});

it('shows each family\'s module progress and paywall time in the admin API', function () {
    [, $family] = freeFamily();
    $family->forceFill(['created_at' => '2026-10-10 08:00:00'])->save();
    Engagement::record($family->id, 'shopping', now()->parse('2026-10-10 09:00:00'));
    Engagement::record($family->id, 'tasks', now()->parse('2026-10-10 10:00:00'));
    Engagement::record($family->id, 'spending', now()->parse('2026-10-10 11:00:00'));

    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = boardToken($admin, adminClient(), OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();

    $item = $this->withToken($token)->getJson("/api/admin/families/{$family->id}")->assertOk()->json('engagement');
    expect(collect($item['modules'])->pluck('name')->all())->toBe(['Shopping', 'Tasks', 'Spending'])
        ->and($item['needed'])->toBe(3)
        ->and($item['engagedAt'])->toStartWith('2026-10-10T11:00:00')
        ->and($item['paywallAt'])->toStartWith('2026-10-11T08:00:00'); // created + 24h, later than engaged

    $listed = collect($this->withToken($token)->getJson('/api/admin/families')->assertOk()->json('items'))->firstWhere('id', $family->id);
    expect(count($listed['engagement']['modules']))->toBe(3)->and($listed['paying'])->toBeFalse();
});

it('queues an added_<module> event for the browser when someone adds an item', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    config(['services.umami.website_id' => 'test-site']);
    [$user, $family] = freeFamily();
    CurrentFamily::set($family->id);
    request()->setLaravelSession(app('session.store'));

    Folder::create(['name' => 'Recipes', 'created_by' => $user->id]); // a container: no event
    Note::create(['title' => 'Groceries', 'created_by' => $user->id]);

    expect(collect(session('analytics_events'))->pluck('name')->all())->toBe(['added_notes']);
});
