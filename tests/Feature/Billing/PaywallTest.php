<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\call;
use function Pest\Laravel\get;

function paidFamily(string $role = 'admin', ?string $status = null): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => $role]);
    $family->forceFill(['subscription_status' => $status])->save();

    return [$user, $family];
}

function stripeOn(): void
{
    config([
        'services.stripe.key' => 'pk_test_x',
        'services.stripe.secret' => 'sk_test_x',
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.stripe.price_monthly' => 'price_m',
        'services.stripe.price_yearly' => 'price_y',
    ]);
}

function signedWebhook(array $event)
{
    $payload = json_encode($event);
    $t = time();
    $sig = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');

    return call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}"], $payload);
}

it('never locks or shows billing when Stripe is not configured (self-hosted)', function () {
    [$user] = paidFamily();
    actingAs($user);

    get('/dashboard')->assertOk();
    get('/settings/billing')->assertNotFound();
});

it('sends an unpaid family to billing and lets a paid or demo family in', function () {
    stripeOn();
    [$user, $family] = paidFamily();
    actingAs($user);

    get('/dashboard')->assertRedirect('/settings/billing');
    get('/settings/billing')->assertOk();
    get('/settings/profile')->assertOk();

    $family->forceFill(['subscription_status' => 'active'])->save();
    get('/dashboard')->assertOk();

    $family->forceFill(['subscription_status' => 'canceled', 'is_demo' => true])->save();
    get('/dashboard')->assertOk();
});

it('opens Stripe Checkout for admins only', function () {
    stripeOn();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/x'])]);

    [$member] = paidFamily('member');
    actingAs($member)->post('/settings/billing/checkout', ['plan' => 'monthly'])->assertForbidden();

    [$admin, $family] = paidFamily();
    $this->flushSession(); // the member's active_family_id would otherwise carry over
    actingAs($admin)->post('/settings/billing/checkout', ['plan' => 'yearly'], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/x');

    Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_y'
        && $r['subscription_data']['metadata']['family_id'] == $family->id);
});

it('applies signed subscription webhooks and rejects unsigned ones', function () {
    stripeOn();
    [, $family] = paidFamily();
    $event = ['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'customer' => 'cus_1', 'status' => 'active', 'metadata' => ['family_id' => (string) $family->id],
    ]]];

    $this->postJson('/stripe/webhook', $event)->assertStatus(400);
    expect($family->fresh()->subscription_status)->toBeNull();

    signedWebhook($event)->assertNoContent();
    expect($family->fresh())->subscription_status->toBe('active')->stripe_customer_id->toBe('cus_1');
});
