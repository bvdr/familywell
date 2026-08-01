<?php

use App\Models\User;
use App\Notifications\PushAlert;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

it('stores and removes a push subscription', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/push/subscribe', [
        'endpoint' => 'https://push.example.com/abc',
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
    ])->assertNoContent();
    expect($user->pushSubscriptions()->count())->toBe(1);

    actingAs($user)->delete('/push/subscribe', ['endpoint' => 'https://push.example.com/abc'])->assertNoContent();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('sends a test push to the current user', function () {
    Notification::fake();
    $user = User::factory()->create();

    actingAs($user)->post('/push/test')->assertNoContent();

    Notification::assertSentTo($user, PushAlert::class);
});
