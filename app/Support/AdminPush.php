<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Push notifications to the admin PWA (admin.familywell.org) about the business: sign-ups, checkouts
 * started, cancellations. The admin's browser subscribes with OUR VAPID key (through the admin Worker),
 * so the app sends them itself, like any other push; no web-push crypto in the Worker, no shared secret.
 *
 * The subscriptions live in push_subscriptions under their own subscribable_type, NOT on the User: a
 * User's subscriptions get every family push (tasks, shopping...), and the admin app must get only these.
 */
class AdminPush
{
    public const TYPE = 'admin_device'; // subscribable_type; subscribable_id = the platform admin's user id

    public static function subscribe(User $admin, string $endpoint, string $key, string $token): void
    {
        // An endpoint is one browser install: re-subscribing (new keys, or another admin signed in there) replaces it.
        PushSubscription::query()->where('endpoint', $endpoint)->delete();
        (new PushSubscription)->forceFill([ // subscribable_* aren't fillable: the package sets them through a relation
            'subscribable_type' => self::TYPE, 'subscribable_id' => $admin->id,
            'endpoint' => $endpoint, 'public_key' => $key, 'auth_token' => $token,
        ])->save();
    }

    public static function unsubscribe(string $endpoint): void
    {
        PushSubscription::query()->where('subscribable_type', self::TYPE)->where('endpoint', $endpoint)->delete();
    }

    /**
     * Notify every admin device, after the response (defer, even an error one): a sign-up or checkout never waits on Apple's or
     * Google's push service, and a push failure is reported, never thrown at the customer. Devices of
     * someone who is no longer a platform admin are skipped. $url is an admin page path ("/users/12").
     */
    public static function send(string $title, string $body, string $url = '/'): void
    {
        defer(function () use ($title, $body, $url) {
            try {
                $devices = PushSubscription::query()->where('subscribable_type', self::TYPE)
                    ->whereIn('subscribable_id', User::query()->where('is_platform_admin', true)->select('id'))->get();
                if ($devices->isNotEmpty()) {
                    Notification::route('WebPush', $devices)->notifyNow(new AdminAlert($title, $body, $url));
                }
            } catch (Throwable $e) {
                report($e);
            }
        })->always(); // also after 4xx: Inertia's redirect to Stripe Checkout is a 409
    }
}
