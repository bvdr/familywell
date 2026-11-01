<?php

use App\Models\Family;
use App\Models\OAuthClient;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

// Comped "Lifetime (VIP)" access: families.access_override = 'lifetime'. Honoured by Billing::hasAccess,
// never written by the Stripe paths, granted/revoked by platform admins (API + console).

function lifetimeStripeOn(): void
{
    config([
        'services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x',
        'services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.price_monthly' => 'price_m',
    ]);
}

function lifetimeFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id, 'name' => 'Lungu']);
    $family->members()->attach($user, ['role' => 'admin']);
    pastFreePeriod($family); // unpaid + free period over = locked, which lifetime access must override

    return [$user, $family];
}

function lifetimeAdminToken(): string
{
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = boardToken($admin, adminClient(), OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();

    return $token;
}

it('lets a lifetime family in with no subscription, shows Lifetime (VIP) and never sends it to checkout', function () {
    lifetimeStripeOn();
    [$user, $family] = lifetimeFamily();
    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.plan')); // unpaid: locked

    $family->forceFill(['access_override' => Family::LIFETIME])->save();
    actingAs($user)->get('/dashboard')->assertOk();
    actingAs($user)->get('/settings/billing')->assertInertia(fn (AssertableInertia $p) => $p
        ->where('subscribed', true)->where('lifetime', true));
    actingAs($user)->post('/settings/billing/checkout', ['plan' => 'monthly'])->assertStatus(409);
});

it('is not revoked by a Stripe webhook cancelling an old subscription', function () {
    lifetimeStripeOn();
    [$user, $family] = lifetimeFamily();
    $family->forceFill(['access_override' => Family::LIFETIME, 'subscription_status' => 'active', 'stripe_customer_id' => 'cus_1'])->save();

    $payload = json_encode(['type' => 'customer.subscription.deleted', 'data' => ['object' => [
        'customer' => 'cus_1', 'status' => 'canceled', 'metadata' => ['family_id' => (string) $family->id],
    ]]]);
    $t = time();
    $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test')], $payload)->assertNoContent();

    expect($family->fresh())->subscription_status->toBe('canceled')->access_override->toBe(Family::LIFETIME);
    actingAs($user)->get('/dashboard')->assertOk();
});

it('is granted and revoked by a platform admin through the admin API, and shown in its lists', function () {
    lifetimeStripeOn();
    [$user, $family] = lifetimeFamily();
    $token = lifetimeAdminToken();

    $this->withToken($token)->putJson('/api/admin/families/999999/lifetime')->assertNotFound();
    $this->withToken($token)->putJson("/api/admin/families/{$family->id}/lifetime")->assertOk()
        ->assertJson(['id' => $family->id, 'lifetime' => true]);
    expect($family->fresh()->access_override)->toBe(Family::LIFETIME);
    $this->withToken($token)->getJson('/api/admin/families')->assertJsonPath('items.0.lifetime', true);
    $this->withToken($token)->getJson('/api/admin/stats')->assertJsonPath('lifetimeFamilies', 1);

    $this->withToken($token)->deleteJson("/api/admin/families/{$family->id}/lifetime")->assertOk()->assertJson(['lifetime' => false]);
    expect($family->fresh()->access_override)->toBeNull();
    auth()->forgetGuards();
    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.plan')); // locked again
});

it('refuses the toggle to anyone but a platform admin', function () {
    [, $family] = lifetimeFamily();
    // A non-admin can't even get an admin token, so: an admin token whose user then loses admin.
    $exAdmin = User::factory()->create(['is_platform_admin' => true]);
    $token = boardToken($exAdmin, adminClient(), OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();
    $exAdmin->forceFill(['is_platform_admin' => false])->save();

    $this->withToken($token)->putJson("/api/admin/families/{$family->id}/lifetime")->assertForbidden();
    expect($family->fresh()->access_override)->toBeNull();
});

it('is granted and revoked from the console', function () {
    [, $family] = lifetimeFamily();

    $this->artisan('family:lifetime', ['family' => $family->id])->expectsOutput("Granted lifetime access: Lungu (#{$family->id})")->assertSuccessful();
    expect($family->fresh()->access_override)->toBe(Family::LIFETIME);
    $this->artisan('family:lifetime', ['family' => $family->id, '--revoke' => true])->assertSuccessful();
    expect($family->fresh()->access_override)->toBeNull();
    $this->artisan('family:lifetime', ['family' => 999999])->assertFailed();
});
