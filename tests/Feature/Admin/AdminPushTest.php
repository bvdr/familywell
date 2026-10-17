<?php

use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\AdminPush;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\PushSubscription;

use function Pest\Laravel\actingAs;

// Pushes to the admin PWA (App\Support\AdminPush). adminApiToken() is in AdminApiTest, stripeOn() /
// paidFamily() / signedWebhook() in Billing/PaywallTest.

function adminDevice(?User $admin = null, string $endpoint = 'https://push.example/admin-1'): User
{
    $admin ??= User::factory()->create(['is_platform_admin' => true]);
    AdminPush::subscribe($admin, $endpoint, 'p256dh-key', 'auth-key');

    return $admin;
}

/** @return list<array{title: string, body: string, url: string, endpoints: list<string>}> */
function adminPushes(): array
{
    $sent = [];
    Notification::assertSentOnDemand(AdminAlert::class, function (AdminAlert $n, array $channels, AnonymousNotifiable $to) use (&$sent) {
        $sent[] = ['title' => $n->title, 'body' => $n->body, 'url' => $n->url, 'endpoints' => $to->routes['WebPush']->pluck('endpoint')->all()];

        return true;
    });

    return $sent;
}

it('subscribes the admin PWA through the admin API, apart from the admin\'s own family pushes', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = adminApiToken($admin);
    config(['webpush.vapid.public_key' => 'BPublicKey']);

    $this->withToken($token)->getJson('/api/admin/push')->assertOk()->assertExactJson(['publicKey' => 'BPublicKey']);
    $this->withToken($token)->putJson('/api/admin/push', ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertOk();

    $device = PushSubscription::query()->sole();
    expect([$device->subscribable_type, $device->subscribable_id])->toBe([AdminPush::TYPE, $admin->id])
        ->and($admin->pushSubscriptions()->count())->toBe(0); // never gets task/shopping pushes

    $this->withToken($token)->deleteJson('/api/admin/push', ['endpoint' => 'https://push.example/a'])->assertOk();
    expect(PushSubscription::query()->count())->toBe(0);
});

it('refuses the push endpoints to anyone but a platform admin', function () {
    $user = User::factory()->create(['is_platform_admin' => true]);
    $token = adminApiToken($user);
    $user->forceFill(['is_platform_admin' => false])->save();

    $this->withToken($token)->putJson('/api/admin/push', ['endpoint' => 'https://push.example/a', 'keys' => ['p256dh' => 'k', 'auth' => 'a']])->assertForbidden();
    expect(PushSubscription::query()->count())->toBe(0);
});

it('pushes a new account to admin devices only, not to people who are no longer admins', function () {
    Notification::fake();
    adminDevice();
    adminDevice(User::factory()->create(['is_platform_admin' => false]), 'https://push.example/ex-admin');

    $this->post('/register', ['name' => 'Ana Pop', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    $ana = User::query()->where('email', 'ana@example.com')->sole();
    expect(adminPushes())->toBe([[
        'title' => 'New account', 'body' => 'Ana Pop (ana@example.com) signed up.', 'url' => "/users/{$ana->id}", 'endpoints' => ['https://push.example/admin-1'],
    ]]);
});

it('sends nothing when no admin has a device', function () {
    Notification::fake();
    $this->post('/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    Notification::assertNothingSent();
});

it('pushes a checkout once Stripe has opened it', function () {
    Notification::fake();
    stripeOn();
    adminDevice();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/x'])]);
    [$owner, $family] = paidFamily();

    actingAs($owner)->post('/settings/billing/checkout', ['plan' => 'yearly'], ['X-Inertia' => 'true'])->assertStatus(409);

    expect(adminPushes())->toMatchArray([[
        'title' => 'Checkout started', 'body' => "{$family->name} opened the yearly checkout ({$owner->name}).", 'url' => "/families/{$family->id}", 'endpoints' => ['https://push.example/admin-1'],
    ]]);
});

it('pushes when a subscriber cancels, and again when the subscription ends', function () {
    Notification::fake();
    stripeOn();
    adminDevice();
    [, $family] = paidFamily(status: 'active');
    $event = fn (string $status, bool $canceling) => ['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'customer' => 'cus_1', 'status' => $status, 'cancel_at_period_end' => $canceling, 'metadata' => ['family_id' => (string) $family->id],
        'items' => ['data' => [['price' => ['id' => 'price_y'], 'current_period_end' => 1794850193]]],
    ]]];

    signedWebhook($event('active', false))->assertNoContent(); // renewal: nothing
    signedWebhook($event('active', true))->assertNoContent();  // canceled in the portal
    signedWebhook($event('active', true))->assertNoContent();  // Stripe resends: no second push
    signedWebhook($event('canceled', false))->assertNoContent(); // the period ran out

    expect(collect(adminPushes())->map(fn ($p) => [$p['title'], $p['body']])->all())->toBe([
        ['Subscription canceled', "{$family->name} canceled, access ends Nov 16, 2026."],
        ['Subscription ended', "{$family->name}: now canceled."],
    ]);
});
