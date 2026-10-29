<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// Settings > Billing pays through the onboarding checkout (components/plan-checkout.tsx -> onboarding.plan.*), so any
// unpaid family's admin may use those endpoints now, not just a locked hard-paywall family. Paid / comped never can.

function settingsCheckoutFamily(string $role = 'admin', array $attrs = []): array
{
    config([
        'services.stripe.key' => 'pk_test_x',
        'services.stripe.secret' => 'sk_test_x',
        'services.stripe.price_monthly' => 'price_m',
        'services.stripe.price_yearly' => 'price_y',
    ]);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->forceFill($attrs)->save(); // a soft (pre-flag) family, still in its free period unless told otherwise
    $family->members()->attach($user, ['role' => $role]);

    return [$user, $family];
}

it('gives an unpaid family the onboarding checkout on the billing page and lets its admin pay through it', function () {
    [$user, $family] = settingsCheckoutFamily();
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_1', 'latest_invoice' => ['confirmation_secret' => ['client_secret' => 'pi_1_secret_x']]]),
    ]);

    actingAs($user)->get('/settings/billing')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/billing')
        ->where('subscribed', false)
        ->where('stripeKey', 'pk_test_x')
        ->where('email', $user->email)
        ->where('tax', null)
        ->where('standard.basic.yearly', 54)
        ->has('tiers.basic'));

    actingAs($user)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertOk()->assertJson(['subscription' => 'sub_1']);
    expect($family->fresh()->stripe_customer_id)->toBe('cus_1');
});

// One family per test: the session's active family carries over between requests within a test.
it('keeps a member out of the checkout', function () {
    [$member] = settingsCheckoutFamily('member');
    actingAs($member)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertForbidden();
});

it('keeps a paid family out of the checkout and shows it its plan', function () {
    [$paid] = settingsCheckoutFamily('admin', ['subscription_status' => 'active']);
    actingAs($paid)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('subscribed', true)->missing('stripeKey'));
    actingAs($paid)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertForbidden();
});

it('keeps a lifetime family out of the checkout', function () {
    [$vip] = settingsCheckoutFamily('admin', ['access_override' => 'lifetime']);
    actingAs($vip)->postJson('/onboarding/plan', ['plan' => 'yearly'])->assertForbidden();
});

it('names the family on the back pill of recently deleted and media', function () {
    [$user, $family] = settingsCheckoutFamily();
    config(['services.stripe.key' => null]);

    actingAs($user)->get('/settings/recently-deleted')->assertInertia(fn ($page) => $page->where('family.name', $family->name));
    actingAs($user)->get('/settings/media')->assertInertia(fn ($page) => $page->where('family.name', $family->name));
});
