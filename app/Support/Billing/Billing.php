<?php

namespace App\Support\Billing;

use App\Models\Family;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The hosted-plan paywall. Everything is gated on enabled() (STRIPE_KEY set): an open-source install
 * without Stripe config never locks and never shows billing UI.
 *
 * ponytail: talks to Stripe's REST API through Laravel's Http client instead of pulling in stripe-php;
 * we only need three calls (checkout session, portal session, session lookup) + webhook signature
 * checking. Switch to stripe-php if billing grows (invoices, proration, coupons...).
 */
class Billing
{
    // Stripe keeps a subscription `past_due` while it retries a failed card (Smart Retries), so the family
    // keeps access during that grace; it flips to unpaid/canceled when retries run out, which locks.
    private const PAID = ['active', 'trialing', 'past_due'];

    public static function enabled(): bool
    {
        return filled(config('services.stripe.key'));
    }

    public static function hasAccess(Family $family): bool
    {
        // The public demo must always open, and self-hosted installs never lock.
        return ! self::enabled() || $family->is_demo || in_array($family->subscription_status, self::PAID, true);
    }

    /** Stripe-hosted Checkout for one of the two plans. Returns the URL to redirect to. */
    public static function checkoutUrl(Family $family, string $email, string $plan): string
    {
        $price = config("services.stripe.price_{$plan}");
        if (! $price) {
            throw new RuntimeException('STRIPE_PRICE_'.strtoupper($plan).' is not set.');
        }

        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'client_reference_id' => $family->id,
            // family_id rides on the SUBSCRIPTION so every later customer.subscription.* webhook maps
            // straight back to the family, with no lookup table and no ordering race vs. checkout events.
            'subscription_data' => ['metadata' => ['family_id' => $family->id]],
            'allow_promotion_codes' => 'true',
            'success_url' => route('billing.show').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.show'),
        ];
        // Re-subscribing after a cancel reuses the family's customer, so the portal shows one history.
        $family->stripe_customer_id
            ? $params['customer'] = $family->stripe_customer_id
            : $params['customer_email'] = $email;

        return self::post('checkout/sessions', $params)['url'];
    }

    /** Stripe-hosted billing portal for the family's customer (change plan, card, cancel, invoices). */
    public static function portalUrl(Family $family): string
    {
        return self::post('billing_portal/sessions', [
            'customer' => $family->stripe_customer_id,
            'return_url' => route('billing.show'),
        ])['url'];
    }

    /**
     * Called on the Checkout success redirect: syncs the family right away so the person isn't bounced
     * back to the paywall while the webhook is still in flight. The webhook stays the source of truth.
     */
    public static function syncCheckoutSession(Family $family, string $sessionId): void
    {
        $session = self::request()->get("checkout/sessions/{$sessionId}", ['expand' => ['subscription']])->throw()->json();
        // Only accept a session that was opened for THIS family (the id comes from the query string).
        if ((string) ($session['client_reference_id'] ?? '') !== (string) $family->id || ! isset($session['subscription']['status'])) {
            return;
        }
        $family->forceFill([
            'stripe_customer_id' => $session['customer'],
            'subscription_status' => $session['subscription']['status'],
        ])->save();
    }

    /** Applies a customer.subscription.* event to the family named in its metadata. */
    public static function applySubscription(array $subscription): void
    {
        $family = Family::find($subscription['metadata']['family_id'] ?? null);
        $family?->forceFill([
            'stripe_customer_id' => $subscription['customer'],
            'subscription_status' => $subscription['status'],
        ])->save();
    }

    /**
     * Verifies a Stripe-Signature header (t=...,v1=...) against the raw payload: HMAC-SHA256 of
     * "{t}.{payload}" with the endpoint secret, within a 5 minute window to block replays.
     */
    public static function validSignature(string $payload, ?string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret || ! $header) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $k === 't' ? $t = $v : ($k === 'v1' ? $sigs[] = $v : null);
        }
        if (! ctype_digit((string) $t) || abs(time() - (int) $t) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$t}.{$payload}", $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private static function post(string $path, array $params): array
    {
        return self::request()->asForm()->post($path, $params)->throw()->json();
    }

    private static function request()
    {
        return Http::baseUrl('https://api.stripe.com/v1/')->withToken(config('services.stripe.secret'))->timeout(15);
    }
}
