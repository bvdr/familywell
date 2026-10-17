<?php

use App\Models\Family;
use App\Support\Billing\Billing;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// The launch offer: the first familywell.pricing.intro_spots families to subscribe pay the intro prices,
// then checkout switches to the standard ones. stripeOn() / paidFamily() come from PaywallTest.php.

function standardPricesOn(int $spots): void
{
    stripeOn();
    config([
        'services.stripe.price_monthly_standard' => 'price_m_std',
        'services.stripe.price_yearly_standard' => 'price_y_std',
        'familywell.pricing' => ['intro_spots' => $spots, 'intro' => ['monthly' => 5, 'yearly' => 45], 'standard' => ['monthly' => 6, 'yearly' => 54]],
    ]);
}

function subscribedFamilies(int $n): void
{
    Family::factory()->count($n)->create()->each(fn ($f) => $f->forceFill(['subscription_plan' => 'monthly', 'subscription_status' => 'active'])->save());
}

it('counts every family that has subscribed against the intro spots, demo excluded', function () {
    standardPricesOn(4);
    subscribedFamilies(2);
    Family::factory()->create()->forceFill(['subscription_status' => 'active'])->save(); // subscribed before subscription_plan existed
    Family::factory()->create()->forceFill(['subscription_plan' => 'yearly', 'subscription_status' => 'canceled'])->save(); // a spot stays claimed
    Family::factory()->create()->forceFill(['is_demo' => true, 'subscription_plan' => 'monthly'])->save();
    Family::factory()->create(); // never subscribed

    expect(Billing::introSpotsLeft())->toBe(0)->and(Billing::pricing())->toMatchArray(['intro' => false, 'spotsLeft' => 0, 'monthly' => 6, 'yearly' => 54]);
});

it('charges the intro price while spots are left and the standard price after', function () {
    standardPricesOn(2);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/x'])]);
    [$admin] = paidFamily();

    expect(Billing::pricing())->toMatchArray(['intro' => true, 'spotsLeft' => 2, 'monthly' => 5, 'yearly' => 45]);
    actingAs($admin)->post('/settings/billing/checkout', ['plan' => 'yearly'], ['X-Inertia' => 'true']);
    Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_y');

    subscribedFamilies(2);
    actingAs($admin)->post('/settings/billing/checkout', ['plan' => 'monthly'], ['X-Inertia' => 'true']);
    Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_m_std');
});

it('keeps the intro prices for good when no standard prices are configured', function () {
    stripeOn();
    config(['familywell.pricing.intro_spots' => 1]);
    subscribedFamilies(3);

    expect(Billing::isIntro())->toBeTrue();
});

it('records the plan for subscriptions on the standard prices too', function () {
    standardPricesOn(0);
    [, $family] = paidFamily();

    Billing::applySubscription(['customer' => 'cus_1', 'status' => 'active', 'metadata' => ['family_id' => (string) $family->id],
        'items' => ['data' => [['price' => ['id' => 'price_y_std']]]]]);

    expect($family->fresh()->subscription_plan)->toBe('yearly');
});

it('serves the price and counter publicly for familywell.org, without a session cookie', function () {
    standardPricesOn(100);
    subscribedFamilies(18);

    get('/pricing.json')->assertOk()
        ->assertJson(['intro' => true, 'spotsLeft' => 82, 'spotsTotal' => 100, 'monthly' => 5, 'yearly' => 45])
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertCookieMissing(config('session.cookie'));
});

it('hides the pricing endpoint when billing is off', function () {
    config(['services.stripe.key' => null]);

    get('/pricing.json')->assertNotFound();
});

it('shows today\'s price on the billing page', function () {
    standardPricesOn(5);
    [$admin] = paidFamily();

    actingAs($admin)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('pricing.monthly', 5)->where('pricing.spotsLeft', 5));
});
