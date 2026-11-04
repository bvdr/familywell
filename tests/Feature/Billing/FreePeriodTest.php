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

// The 5-day free trial: an unpaid family keeps full access for Billing::TRIAL_DAYS after it was made, no card, then
// gets the plan selection. Engagement (modules used) no longer gates it; it still feeds the admin's progress bar.

function freeFamily(): array
{
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    return [$user, $family];
}

it('keeps a new unpaid family in for 5 days, however much it uses, then shows the plan selection', function () {
    [$user, $family] = freeFamily();
    foreach (['shopping', 'tasks', 'spending', 'notes'] as $module) {
        Engagement::record($family->id, $module); // heavy use doesn't end the trial early
    }
    actingAs($user)->get('/dashboard')->assertOk();

    $this->travel(4)->days();
    actingAs($user)->get('/dashboard')->assertOk();
    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('trialDaysLeft', 1));

    $this->travel(1)->days();
    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.plan'));
    actingAs($user)->get('/onboarding/plan')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/plan')->where('trialDaysLeft', 0));
});

it('starts the trial from the plan step with no card, only while it lasts', function () {
    [$user, $family] = freeFamily();
    $user->forceFill(['onboarding_step' => 'plan'])->save();
    session(['active_family_id' => $family->id]);

    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('trialDaysLeft', 5));
    actingAs($user)->post('/onboarding/plan/trial')->assertRedirect(route('onboarding.ready'));
    expect($user->fresh()->onboarding_step)->toBe('ready');

    // After the trial the plan step is the paywall: no way past it without paying.
    pastFreePeriod($family);
    $user->forceFill(['onboarding_step' => 'plan'])->save();
    actingAs($user->fresh())->post('/onboarding/plan/trial')->assertForbidden();
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
        ->and($family->fresh()->engaged_at)->toBeNull(); // one module: not engaged yet
});

it('lets a free-period family open the plans and go back; a paid one sees its plan', function () {
    [$user, $family] = freeFamily();

    // Billing is the paywall while unpaid: back to Settings during the trial, no way back after it.
    actingAs($user)->get('/settings/billing')->assertRedirect(route('onboarding.plan'));
    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('back', route('settings.family')));

    pastFreePeriod($family);
    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('back', null));

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
        ->and($item['paywallAt'])->toStartWith('2026-10-15T08:00:00'); // created + the 5-day trial

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
