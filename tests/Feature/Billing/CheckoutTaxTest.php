<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// Stripe Tax on the inline checkout: the rate comes from the buyer's IP (like Stripe Checkout), Plan & pay shows it
// on top of the price, and the subscription is created with automatic tax so the card is charged that total.

function taxFamily(): User
{
    config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.tax' => true,
        'services.stripe.price_yearly' => 'price_y', 'services.stripe.price_monthly' => 'price_m']);
    $user = User::factory()->create(['onboarding_step' => 'plan']);
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->forceFill(['hard_paywall' => true])->save();
    $family->members()->attach($user, ['role' => 'admin']);

    return $user;
}

function fakeStripeTax(): void
{
    Http::fake([
        'api.stripe.com/v1/tax/calculations' => Http::response(['customer_details' => ['address' => ['country' => 'RO']],
            'tax_breakdown' => [['tax_rate_details' => ['percentage_decimal' => '21.0']]]]),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'latest_invoice' => ['confirmation_secret' => ['client_secret' => 'pi_secret']]]),
    ]);
}

it('shows the tax rate for where the buyer is and charges it through automatic tax', function () {
    $user = taxFamily();
    fakeStripeTax();
    $ip = ['CF-Connecting-IP' => '5.2.128.1'];

    actingAs($user)->withHeaders($ip)->get('/onboarding/plan')
        ->assertInertia(fn ($page) => $page->where('tax', ['country' => 'RO', 'percent' => 21]));
    actingAs($user)->withHeaders($ip)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/customers/cus_1' && $r['tax']['ip_address'] === '5.2.128.1');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && ($r['automatic_tax']['enabled'] ?? null) === 'true');
});

it('adds no tax when Stripe Tax is off', function () {
    $user = taxFamily();
    config(['services.stripe.tax' => false]);
    fakeStripeTax();

    actingAs($user)->get('/onboarding/plan')->assertInertia(fn ($page) => $page->where('tax', null));
    actingAs($user)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'tax/calculations'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/subscriptions' && ! isset($r['automatic_tax']));
});
