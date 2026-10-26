<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// The plan step: after the tour, only where billing is on, paid through Stripe Checkout, back into the app.

function planBilling(): void
{
    config([
        'services.stripe.key' => 'pk_test_x',
        'services.stripe.secret' => 'sk_test_x',
        'services.stripe.price_monthly' => 'price_m',
        'services.stripe.price_yearly' => 'price_y',
    ]);
}

function planOwner(): array
{
    $user = User::factory()->create(['name' => 'Ana Rivera']);
    $family = Family::create(['name' => 'The Riveras', 'owner_id' => $user->id, 'color' => '#FFDF00']);
    $family->members()->attach($user, ['role' => 'admin']);
    $user->forceFill(['onboarding_step' => 'plan'])->save();

    return [$user, $family];
}

it('skips the plan where billing is off (self-hosted, dev)', function () {
    [$user] = planOwner();

    actingAs($user)->get('/onboarding/plan')->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBeNull();
});

it('goes from the tour to the plan where billing is on', function () {
    planBilling();
    [$user] = planOwner();
    $user->forceFill(['onboarding_step' => 'tour'])->save();

    actingAs($user)->post('/onboarding/tour')->assertRedirect(route('onboarding.plan'));
    actingAs($user->fresh())->get('/onboarding/plan')->assertOk()->assertInertia(fn ($page) => $page
        ->component('onboarding/plan')
        ->where('pricing.monthly', config('familywell.pricing.intro.monthly'))
        ->where('canWait', true));
});

it('opens Stripe Checkout that comes back to the onboarding', function () {
    planBilling();
    [$user] = planOwner();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/s'])]);

    actingAs($user)->post('/onboarding/plan', ['plan' => 'yearly'])->assertRedirect('https://checkout.stripe.test/s');

    Http::assertSent(fn ($r) => str_contains($r->body(), urlencode(route('onboarding.plan.paid')))
        && str_contains($r->body(), 'price_y'));
});

it('lets a family in its free period look around first', function () {
    planBilling();
    [$user] = planOwner();

    actingAs($user)->post('/onboarding/plan/later')->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBeNull();
});
