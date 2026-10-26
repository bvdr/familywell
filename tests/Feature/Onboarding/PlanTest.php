<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Billing\Billing;
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

it('starts an incomplete subscription on the intro price and hands back only the client secret', function () {
    planBilling();
    [$user, $family] = planOwner();
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'latest_invoice' => ['confirmation_secret' => ['client_secret' => 'pi_1_secret_x']]]),
    ]);

    actingAs($user)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk()
        ->assertExactJson(['subscription' => 'sub_1', 'clientSecret' => 'pi_1_secret_x']);

    expect($family->fresh()->stripe_customer_id)->toBe('cus_1');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscriptions')
        && $r['payment_behavior'] === 'default_incomplete'
        && $r['items'][0]['price'] === 'price_y'
        && $r['metadata']['family_id'] == $family->id
        && $r->header('Stripe-Version')[0] === Billing::INLINE_API_VERSION);
});

it('finishes the onboarding once the paid subscription syncs, and only for this family', function () {
    planBilling();
    [$user, $family] = planOwner();
    $sub = fn ($familyId, $status) => (['id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status, 'metadata' => ['family_id' => $familyId],
        'items' => ['data' => [['price' => ['id' => 'price_y'], 'current_period_end' => now()->addYear()->timestamp]]]]);
    // One subscription read per visit: first one that belongs to another family (ignored), then theirs.
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()->push($sub(999, 'active'))->push($sub($family->id, 'active'))]);

    actingAs($user)->get('/onboarding/plan/paid?subscription=sub_1')->assertRedirect(route('onboarding.plan', ['failed' => 1]));

    actingAs($user)->get('/onboarding/plan/paid?subscription=sub_1')->assertOk()->assertInertia(fn ($page) => $page->where('paid', true));
    expect($user->fresh()->onboarding_step)->toBeNull()->and($family->fresh()->subscription_status)->toBe('active');
});

it('lets a family in its free period look around first', function () {
    planBilling();
    [$user] = planOwner();

    actingAs($user)->post('/onboarding/plan/later')->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBeNull();
});
