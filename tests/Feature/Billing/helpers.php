<?php

// Helpers shared by several test files. In their own file (require_once) so a parallel run (pest --parallel),
// where a worker may never load the file that used to hold them, still finds them.

use App\Models\Family;
use App\Models\User;

use function Pest\Laravel\call;

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

function paidFamily(string $role = 'admin', ?string $status = null): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => $role]);
    $family->forceFill(['subscription_status' => $status])->save();
    pastFreePeriod($family); // the paywall states below are about a family whose free period is over

    return [$user, $family];
}

function signedWebhook(array $event)
{
    $payload = json_encode($event);
    $t = time();
    $sig = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');

    return call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}"], $payload);
}
