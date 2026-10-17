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
    pastFreePeriod($family); // the paywall states below are about a family whose free period is over

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

it('tracks a subscription starting and ending once each, under the family owner', function () {
    stripeOn();
    $events = umamiOn();
    [$owner, $family] = paidFamily();
    $subscription = fn (string $status) => ['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'customer' => 'cus_1', 'status' => $status, 'metadata' => ['family_id' => (string) $family->id],
        'items' => ['data' => [['price' => ['id' => 'price_y']]]],
    ]]];

    signedWebhook($subscription('incomplete'))->assertNoContent();
    signedWebhook($subscription('active'))->assertNoContent();
    signedWebhook($subscription('active'))->assertNoContent();   // renewal: no new event
    signedWebhook($subscription('past_due'))->assertNoContent(); // still has access
    signedWebhook($subscription('canceled'))->assertNoContent();

    expect($events())->toBe([
        ['subscription_started', ['plan' => 'yearly'], $owner->analytics_id, '/billing'],
        ['subscription_canceled', [], $owner->analytics_id, '/billing'],
    ]);
});

it('queues checkout_completed for the browser when Stripe sends the buyer back paid', function () {
    stripeOn();
    umamiOn();
    [$owner, $family] = paidFamily();
    Http::fake(['api.stripe.com/v1/checkout/sessions/cs_1*' => Http::response([
        'client_reference_id' => (string) $family->id, 'customer' => 'cus_1',
        'subscription' => ['status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_m']]]]],
    ])]);

    actingAs($owner)->get('/settings/billing?session_id=cs_1')->assertRedirect(route('billing.show'));

    actingAs($owner)->get('/settings/billing')->assertInertia(fn ($page) => $page
        ->where('analyticsEvents.0.name', 'checkout_completed')->has('analyticsEvents', 1));
});

it('keeps the plan, period end and cancel-at-period-end from Stripe for the Plan card', function () {
    stripeOn();
    [$owner, $family] = paidFamily();
    // Current API versions carry current_period_end on the subscription item, not the subscription.
    $event = fn (bool $canceling) => ['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'customer' => 'cus_1', 'status' => 'active', 'cancel_at_period_end' => $canceling,
        'metadata' => ['family_id' => (string) $family->id],
        'items' => ['data' => [['price' => ['id' => 'price_y'], 'current_period_end' => 1794850193]]],
    ]]];

    signedWebhook($event(false))->assertNoContent();
    actingAs($owner)->get('/settings/billing')->assertInertia(fn ($page) => $page
        ->where('plan', 'yearly')->where('canceling', false)
        ->where('periodEnd', fn ($iso) => Carbon\Carbon::parse($iso)->timestamp === 1794850193));

    signedWebhook($event(true))->assertNoContent();
    actingAs($owner)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('canceling', true));
});

it('backfills the plan once for a family that subscribed before it was stored', function () {
    stripeOn();
    [$owner, $family] = paidFamily(status: 'active');
    $family->forceFill(['stripe_customer_id' => 'cus_old'])->save();
    Http::fake(['api.stripe.com/v1/subscriptions*' => Http::response(['data' => [[
        'customer' => 'cus_old', 'status' => 'active',
        'items' => ['data' => [['price' => ['id' => 'price_m'], 'current_period_end' => 1794850193]]],
    ]]])]);

    actingAs($owner)->get('/settings/billing')->assertInertia(fn ($page) => $page->where('plan', 'monthly'));
    actingAs($owner)->get('/settings/billing')->assertOk();

    Http::assertSentCount(1); // known now: the second visit doesn't ask Stripe again
    expect($family->fresh()->subscription_status)->toBe('active');
});

it('still renders the Plan card when the backfill call fails', function () {
    stripeOn();
    [$owner, $family] = paidFamily(status: 'active');
    $family->forceFill(['stripe_customer_id' => 'cus_old'])->save();
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'nope']], 500)]);

    actingAs($owner)->get('/settings/billing')->assertOk()->assertInertia(fn ($page) => $page->where('plan', null));
});

it('opens checkout with no Stripe trial: the free period before the paywall is the trial', function () {
    stripeOn();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/x'])]);
    [$admin] = paidFamily();

    actingAs($admin)->post('/settings/billing/checkout', ['plan' => 'monthly']);
    Http::assertSent(fn ($r) => ! isset($r['subscription_data']['trial_period_days']));
});
