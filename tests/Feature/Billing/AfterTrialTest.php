<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Billing\Billing;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// The paywall after the 5-day free trial (FreePeriodTest has the trial itself): an unpaid family past it can't get
// past the plan selection without paying, on any route, write or connector.

function lockedFamily(array $attrs = []): array
{
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->forceFill(['created_at' => now()->subDays(Billing::TRIAL_DAYS + 1), ...$attrs])->save();
    $family->members()->attach($user, ['role' => 'admin']);

    return [$user, $family];
}

it('sends an unpaid new family back to the plan step from the dashboard and every module route', function () {
    [$user] = lockedFamily();

    foreach (['/dashboard', '/m/shopping', '/m/tasks', '/m/notes'] as $url) {
        actingAs($user->fresh())->get($url)->assertRedirect(route('onboarding.plan'));
    }
    // The plan step opens for a locked family on its own, with no onboarding step to open it.
    expect($user->fresh()->onboarding_step)->toBeNull();
    actingAs($user->fresh())->get('/onboarding/plan')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/plan'));
});

it('does not let the tour or the recap be a way in for a family that has not paid', function () {
    [$user] = lockedFamily();

    // Forging the later steps (a POST to the recap starts the tour) still lands on the plan.
    $user->forceFill(['onboarding_step' => 'ready'])->save();
    actingAs($user)->post('/onboarding/ready', ['tour' => true])->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBe('tour');
    actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('onboarding.plan'));
    actingAs($user->fresh())->get('/m/tasks')->assertRedirect(route('onboarding.plan'));
});

it('takes a lapsed family that pays again straight back into the app, with no second onboarding', function () {
    [$user, $family] = lockedFamily(['subscription_status' => 'canceled']);
    Http::fake(['api.stripe.com/v1/subscriptions/sub_9' => Http::response(['id' => 'sub_9', 'customer' => 'cus_1', 'status' => 'active', 'metadata' => ['family_id' => $family->id],
        'items' => ['data' => [['price' => ['id' => 'price_y'], 'current_period_end' => now()->addYear()->timestamp]]]])]);

    actingAs($user)->get('/onboarding/plan/paid?subscription=sub_9')->assertOk()
        ->assertInertia(fn ($page) => $page->where('paid', true)->where('next', route('dashboard')));
    actingAs($user->fresh())->get('/dashboard')->assertOk();
});

it('blocks writes too, not just pages', function () {
    [$user] = lockedFamily();

    actingAs($user)->post('/m/notes', ['title' => 'x'])->assertRedirect(route('onboarding.plan'));
});

it('keeps Billing and sign-out open for a locked family', function () {
    [$user] = lockedFamily();

    actingAs($user)->get('/settings/billing')->assertOk()->assertInertia(fn ($page) => $page->where('locked', true)->where('subscribed', false));
    actingAs($user)->post('/logout')->assertRedirect();
    $this->assertGuest();
});

it('sends a locked member who cannot pay to the Billing page instead', function () {
    [, $family] = lockedFamily();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    actingAs($member)->get('/dashboard')->assertRedirect(route('billing.show'));
});

it('locks the connectors (MCP) of an unpaid new family', function () {
    [$user, $family] = lockedFamily();
    $family->forceFill(['created_at' => now()->subDays(30)])->save();

    expect(Billing::hasAccess($family->fresh()))->toBeFalse(); // McpFamilyContext asks exactly this
});

it('lets a paid new family in, and locks it again when the subscription ends', function () {
    [$user, $family] = lockedFamily(['subscription_status' => 'active']);
    actingAs($user)->get('/dashboard')->assertOk();

    $family->forceFill(['subscription_status' => 'canceled'])->save();
    actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('onboarding.plan'));
});

it('lets a lifetime (comped) new family in', function () {
    [$user, $family] = lockedFamily();
    Billing::setLifetime($family, true, 'test');

    actingAs($user)->get('/dashboard')->assertOk();
});

it('never locks when billing is off (self-hosted)', function () {
    [$user] = lockedFamily();
    config(['services.stripe.key' => null]);

    actingAs($user)->get('/dashboard')->assertOk();
});

it('starts every family made by the onboarding on the free trial', function () {
    config(['services.stripe.key' => 'pk_test_x']);
    $user = User::factory()->create(['name' => 'Ana']);
    $user->forceFill(['onboarding_step' => null])->save();

    actingAs($user)->post('/onboarding', ['name' => 'The Riveras', 'color' => '#FFDF00'])->assertRedirect(route('onboarding.invite'));

    $family = Family::where('owner_id', $user->id)->first();
    expect(Billing::hasAccess($family))->toBeTrue()->and(Billing::trialDaysLeft($family))->toBe(Billing::TRIAL_DAYS);
});

it('moves a family made VIP while on the plan step past checkout to the recap', function () {
    [$user, $family] = lockedFamily();
    $user->forceFill(['onboarding_step' => 'plan'])->save();
    session(['active_family_id' => $family->id]);
    actingAs($user)->get('/onboarding/plan')->assertOk(); // unpaid: the checkout

    Billing::setLifetime($family, true, 'admin@test'); // the admin's VIP toggle

    actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('onboarding.plan'));
    actingAs($user->fresh())->get('/onboarding/plan')->assertRedirect(route('onboarding.ready'));
    expect($user->fresh()->onboarding_step)->toBe('ready');
});
