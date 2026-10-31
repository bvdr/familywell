<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// Promo codes on Plan & pay (design 06a): checked against Stripe's promotion codes when applied, kept in the
// session, and put on the subscription as a discount when they pay.

function promoFamily(): User
{
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.tax' => false,
        'services.stripe.price_yearly' => 'price_y', 'services.stripe.price_monthly' => 'price_m']);
    $user = User::factory()->create(['onboarding_step' => 'plan']);
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->forceFill(['hard_paywall' => true])->save();
    $family->members()->attach($user, ['role' => 'admin']);

    return $user;
}

function fakeStripePromo(): void
{
    Http::fake([
        // Stripe's current shape: the coupon sits under `promotion`.
        'api.stripe.com/v1/promotion_codes?code=FRIENDS10*' => Http::response(['data' => [['id' => 'promo_1', 'code' => 'FRIENDS10',
            'promotion' => ['type' => 'coupon', 'coupon' => ['valid' => true, 'percent_off' => 10.0, 'amount_off' => null, 'currency' => null, 'duration' => 'once']]]]]),
        'api.stripe.com/v1/promotion_codes*' => Http::response(['data' => []]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'latest_invoice' => ['confirmation_secret' => ['client_secret' => 'pi_secret']]]),
    ]);
}

it('applies a valid code and puts it on the subscription', function () {
    $user = promoFamily();
    fakeStripePromo();

    actingAs($user)->postJson('/onboarding/plan/promo', ['code' => 'friends10'])->assertOk()
        ->assertJson(['id' => 'promo_1', 'code' => 'FRIENDS10', 'percent' => 10, 'duration' => 'once']);
    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('promo.code', 'FRIENDS10'));
    actingAs($user)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && $r['discounts'][0]['promotion_code'] === 'promo_1');
});

it('turns down an unknown code with a line to show, and keeps nothing', function () {
    $user = promoFamily();
    fakeStripePromo();

    actingAs($user)->postJson('/onboarding/plan/promo', ['code' => 'NOPE'])->assertStatus(422)->assertJsonPath('message', "That code isn't valid or has expired.");
    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('promo', null));
});

it('removes an applied code', function () {
    $user = promoFamily();
    fakeStripePromo();

    actingAs($user)->postJson('/onboarding/plan/promo', ['code' => 'FRIENDS10'])->assertOk();
    actingAs($user)->deleteJson('/onboarding/plan/promo')->assertNoContent();
    actingAs($user)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && ! isset($r['discounts']));
});
