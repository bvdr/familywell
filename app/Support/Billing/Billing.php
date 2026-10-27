<?php

namespace App\Support\Billing;

use App\Models\Family;
use App\Models\User;
use App\Support\AdminPush;
use App\Support\Analytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    // No paywall right after signup (23 checkouts in the first week, 2 payments): an unpaid family keeps
    // full access until it has added items in Engagement::MODULES_NEEDED modules AND is this old. That free
    // use IS the trial, so checkout has no Stripe trial (the 3-day one from #224 was dropped for this).
    // 'trialing' stays in PAID for any subscription that started on that trial.
    public const GRACE_HOURS = 24;

    public static function enabled(): bool
    {
        return filled(config('services.stripe.key'));
    }

    /**
     * May this family use the app? Paid (or comped, demo, self-hosted), or still in its free period.
     *
     * Hard paywall: a family made by the onboarding (families.hard_paywall = true, set only there) has NO free
     * period: it needs a paying subscription from the first minute, so the plan step can't be skipped. This is the
     * one shared check (RequireFamily for the web app, McpFamilyContext for connectors), so no route can sneak past.
     * Families that existed before the flag keep the soft rule above, so nobody who has access today is locked out.
     * isPaid() already exempts self-hosted installs, the demo and lifetime (comped) families from both rules.
     */
    public static function hasAccess(Family $family): bool
    {
        return self::isPaid($family) || (! $family->hard_paywall && ! self::paywallDue($family));
    }

    /**
     * Paying or not needing to: the public demo always opens, self-hosted installs never lock, and a comped
     * family (lifetime VIP) has access whatever Stripe says (the Stripe paths never write access_override).
     * The Billing page shows the plan card for these and the plans for everyone else.
     */
    public static function isPaid(Family $family): bool
    {
        return ! self::enabled() || $family->is_demo || self::isLifetime($family)
            || in_array($family->subscription_status, self::PAID, true);
    }

    /**
     * The free period is over: the family used enough modules (engaged_at, see Engagement) and is at least
     * GRACE_HOURS old. Both are on the row already, so the per-request gate costs no extra query.
     */
    public static function paywallDue(Family $family): bool
    {
        return $family->engaged_at !== null && $family->created_at->lte(now()->subHours(self::GRACE_HOURS));
    }

    public static function isLifetime(Family $family): bool
    {
        return $family->access_override === Family::LIFETIME;
    }

    /**
     * Grant or revoke comped lifetime access. The one write path for the flag, shared by the admin API
     * (admin.familywell.org's toggle) and `php artisan family:lifetime`, and logged for the audit trail.
     */
    public static function setLifetime(Family $family, bool $on, string $by): void
    {
        $family->forceFill(['access_override' => $on ? Family::LIFETIME : null])->save();
        Log::info('billing: lifetime access '.($on ? 'granted' : 'revoked'), ['family_id' => $family->id, 'by' => $by]);
    }

    /**
     * Intro spots still open: config pricing.intro_spots minus every family that has ever subscribed. Any
     * Stripe subscription sets subscription_status (since billing launched) and subscription_plan (since
     * 2026-10-16, backfilled lazily, so older subscribers can still have it empty: hence either column).
     * Both are kept after a cancel, so a spot, once claimed, stays claimed. Counting all subscribers works because the switch is one-way: once the
     * spots are gone nobody gets the intro price again, so the first N subscribers ARE the intro ones.
     * ponytail: two families checking out at the same moment for the last spot can both get it; at 100
     * spots one extra intro subscriber is fine, a row lock would be the upgrade.
     */
    public static function introSpotsLeft(): int
    {
        $claimed = Family::query()->where('is_demo', false)
            ->where(fn ($q) => $q->whereNotNull('subscription_plan')->orWhereNotNull('subscription_status'))->count();

        return max(0, (int) config('familywell.pricing.intro_spots') - $claimed);
    }

    /** Intro prices apply while spots are left, or for good when no standard prices are configured. */
    public static function isIntro(): bool
    {
        $standard = config('services.stripe.price_monthly_standard') && config('services.stripe.price_yearly_standard');

        return ! $standard || self::introSpotsLeft() > 0;
    }

    /**
     * What a new subscriber pays right now, for the billing page and familywell.org's pricing card
     * (GET /pricing.json): whole-dollar amounts + the counter.
     *
     * `monthly` / `yearly` are Basic's (what familywell.org shows); `pro` is the same pair for the Pro tier, only
     * present when Pro is on offer (proAvailable).
     *
     * @return array{intro: bool, spotsLeft: int, spotsTotal: int, monthly: int, yearly: int, pro?: array{monthly: int, yearly: int}}
     */
    public static function pricing(): array
    {
        $intro = self::isIntro();
        $amounts = config('familywell.pricing.'.($intro ? 'intro' : 'standard'));
        $pro = config('familywell.pricing.pro.'.($intro ? 'intro' : 'standard'));

        return [
            'intro' => $intro,
            'spotsLeft' => $intro ? self::introSpotsLeft() : 0,
            'spotsTotal' => (int) config('familywell.pricing.intro_spots'),
            'monthly' => (int) $amounts['monthly'],
            'yearly' => (int) $amounts['yearly'],
        ] + (self::proAvailable() ? ['pro' => ['monthly' => (int) $pro['monthly'], 'yearly' => (int) $pro['yearly']]] : []);
    }

    /**
     * Is the Pro tier on offer? Only when its Stripe prices exist (both cycles, and the standard pair too once
     * the intro spots are gone): the amounts alone are display copy, so without prices there is nothing to charge.
     */
    public static function proAvailable(): bool
    {
        $keys = ['price_monthly_pro', 'price_yearly_pro'];
        if (config('services.stripe.price_monthly_standard') && config('services.stripe.price_yearly_standard')) {
            array_push($keys, 'price_monthly_pro_standard', 'price_yearly_pro_standard');
        }

        return collect($keys)->every(fn ($k) => filled(config("services.stripe.{$k}")));
    }

    /** The Stripe price id for a tier + billing cycle at today's price (intro while spots last). */
    private static function priceId(string $tier, string $plan): string
    {
        $key = 'price_'.$plan.($tier === 'pro' ? '_pro' : '').(self::isIntro() ? '' : '_standard');

        return config("services.stripe.{$key}") ?: throw new RuntimeException('STRIPE_'.strtoupper($key).' is not set.');
    }

    /** Stripe-hosted Checkout for one of the two plans, at today's price. Returns the URL to redirect to. */
    public static function checkoutUrl(Family $family, string $email, string $plan): string
    {
        $key = self::isIntro() ? "price_{$plan}" : "price_{$plan}_standard";
        $price = config("services.stripe.{$key}");
        if (! $price) {
            throw new RuntimeException('STRIPE_'.strtoupper($key).' is not set.');
        }

        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'client_reference_id' => $family->id,
            // family_id rides on the SUBSCRIPTION so every later customer.subscription.* webhook maps
            // straight back to the family, with no lookup table and no ordering race vs. checkout events.
            'subscription_data' => ['metadata' => ['family_id' => $family->id]],
            'allow_promotion_codes' => 'true',
            // Checkout locates the buyer itself (IP first, then the billing address it asks for).
            ...(config('services.stripe.tax') ? ['automatic_tax' => ['enabled' => 'true'], 'billing_address_collection' => 'required'] : []),
            'success_url' => route('billing.show').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.show'),
        ];
        // Re-subscribing after a cancel reuses the family's customer, so the portal shows one history.
        $family->stripe_customer_id
            ? $params['customer'] = $family->stripe_customer_id
            : $params['customer_email'] = $email;
        if (config('services.stripe.tax') && $family->stripe_customer_id) {
            // An existing customer: Checkout may only use the address it collects if it can save it back.
            $params['customer_update'] = ['address' => 'auto'];
        }

        return self::post('checkout/sessions', $params)['url'];
    }

    /**
     * The inline checkout (onboarding Plan & pay, Stripe Elements): an INCOMPLETE subscription on today's price
     * (intro or standard, like checkoutUrl) whose first invoice the browser pays with the Payment Element or Apple /
     * Google Pay (Express Checkout Element). Returns the subscription id and the client secret to confirm with.
     * The customer is created once per family and reused. The webhook stays the source of truth for the status.
     *
     * Pinned to an API version that has `invoice.confirmation_secret`, whatever the account's default is.
     *
     * @return array{subscription: string, clientSecret: string}
     */
    public static function startSubscription(Family $family, string $email, string $plan, string $tier = 'basic', ?string $ip = null, ?string $promo = null): array
    {
        abort_if($tier === 'pro' && ! self::proAvailable(), 422, 'Pro is not on offer.');
        $price = self::priceId($tier, $plan);
        // Tax only when Plan & pay could show it (taxRate resolved a location for this IP): then the amount the
        // page showed is the amount charged. No rate = no tax line on the page and none on the invoice.
        $taxed = $ip !== null && self::taxRate($ip) !== null;

        if (! $family->stripe_customer_id) {
            $customer = self::post('customers', ['email' => $email, 'name' => $family->name, 'metadata' => ['family_id' => $family->id]]);
            $family->forceFill(['stripe_customer_id' => $customer['id']])->save();
        }
        if ($taxed) {
            // Where the buyer is, like Stripe Checkout: Stripe Tax locates the customer from their IP.
            self::post('customers/'.$family->stripe_customer_id, ['tax' => ['ip_address' => $ip]]);
        }

        $sub = self::request()->withHeaders(['Stripe-Version' => self::INLINE_API_VERSION])->asForm()->post('subscriptions', [
            'customer' => $family->stripe_customer_id,
            'items' => [['price' => $price]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            // Same as Checkout: the webhook maps every customer.subscription.* event back to the family.
            'metadata' => ['family_id' => $family->id],
            'expand' => ['latest_invoice.confirmation_secret'],
        ] + ($promo ? ['discounts' => [['promotion_code' => $promo]]] : []) + ($taxed ? ['automatic_tax' => ['enabled' => 'true']] : []))->throw()->json();

        return ['subscription' => $sub['id'], 'clientSecret' => $sub['latest_invoice']['confirmation_secret']['client_secret']];
    }

    /**
     * A promo code someone typed on Plan & pay, looked up in Stripe's promotion codes (the same ones hosted
     * Checkout accepts). Null when there's no such active code or its coupon can't be redeemed any more. The
     * discount is read from the coupon: `percent` off, or `amount` off in dollars (USD coupons only, our prices'
     * currency). Stripe checks the rest (redemption limits, first-time only) again when the subscription is made.
     *
     * @return array{id: string, code: string, percent: ?float, amount: ?float, duration: string}|null
     */
    public static function promoCode(string $code): ?array
    {
        $found = self::request()->get('promotion_codes', ['code' => $code, 'active' => 'true', 'limit' => 1,
            'expand' => ['data.promotion.coupon', 'data.coupon']])->throw()->json('data.0');
        // Newer API versions put the coupon under `promotion`; older ones on the promotion code itself.
        $coupon = $found['promotion']['coupon'] ?? $found['coupon'] ?? null;
        if (! $found || ! is_array($coupon) || ! ($coupon['valid'] ?? false)) {
            return null;
        }
        if ($coupon['amount_off'] && strtolower((string) $coupon['currency']) !== 'usd') {
            return null;
        }

        return [
            'id' => $found['id'],
            'code' => $found['code'],
            'percent' => $coupon['percent_off'] !== null ? (float) $coupon['percent_off'] : null,
            'amount' => $coupon['amount_off'] !== null ? $coupon['amount_off'] / 100 : null,
            'duration' => $coupon['duration'],
        ];
    }

    /**
     * The tax rate for a buyer at this IP, the way Stripe Checkout estimates it before an address is typed: Stripe
     * Tax works out the location from the IP. Null when tax is off, Stripe has nothing to charge there (no
     * registration covers it, e.g. the US today) or the lookup fails, and then the page shows no tax line.
     * Cached a day per IP: the rate for a place doesn't change mid-checkout, and Plan & pay re-renders on every
     * plan / tier switch.
     *
     * @return array{country: string, percent: float}|null
     */
    public static function taxRate(string $ip): ?array
    {
        if (! config('services.stripe.tax')) {
            return null;
        }

        return Cache::remember('stripe-tax:'.$ip, now()->addDay(), function () use ($ip) {
            try {
                // A $100 line: the rate is all we need; the page applies it to whichever price is picked.
                $calc = self::post('tax/calculations', [
                    'currency' => 'usd',
                    'line_items' => [['amount' => 10000, 'reference' => 'rate']],
                    'customer_details' => ['ip_address' => $ip],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Stripe tax rate lookup failed', ['error' => $e->getMessage()]);

                return null;
            }
            // Located from the IP, Stripe leaves customer_details.address empty: the country is on the rate itself.
            $details = $calc['tax_breakdown'][0]['tax_rate_details'] ?? [];
            $rate = $details['percentage_decimal'] ?? null;
            $country = $details['country'] ?? null;

            return $rate && (float) $rate > 0 && $country ? ['country' => $country, 'percent' => (float) $rate] : null;
        });
    }

    /** After the browser confirmed the payment: read the subscription now and store it (only if it's this family's). */
    public static function syncSubscription(Family $family, string $subscriptionId): void
    {
        $sub = self::request()->get('subscriptions/'.rawurlencode($subscriptionId))->throw()->json();
        if ((string) ($sub['metadata']['family_id'] ?? '') !== (string) $family->id) {
            return;
        }
        self::setStatus($family, $sub['customer'], $sub);
    }

    /** Best effort: drop an abandoned incomplete subscription (a plan switch mid-checkout). Never throws. */
    public static function cancelIncomplete(string $subscriptionId): void
    {
        try {
            $sub = self::request()->get('subscriptions/'.rawurlencode($subscriptionId))->json();
            if (($sub['status'] ?? null) === 'incomplete') {
                self::request()->delete('subscriptions/'.rawurlencode($subscriptionId));
            }
        } catch (\Throwable) {
            // Stripe expires incomplete subscriptions after 23 hours on its own.
        }
    }

    /** Apple Pay / Google Pay need the site's domain registered with Stripe (stripe:register-domains). */
    public static function registerDomain(string $domain): array
    {
        return self::post('payment_method_domains', ['domain_name' => $domain]);
    }

    public const INLINE_API_VERSION = '2025-03-31.basil';

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
        self::setStatus($family, $session['customer'], $session['subscription']);
    }

    /**
     * One-time backfill for families that subscribed before the plan columns existed: reads the customer's
     * newest subscription and stores it like a webhook would. Called by the Billing page only while the
     * plan is unknown, so it costs one Stripe call per such family, ever. Never throws: a Stripe hiccup
     * just leaves the card without the plan details until the next webhook.
     */
    public static function backfillPlan(Family $family): void
    {
        if ($family->subscription_plan || ! $family->stripe_customer_id) {
            return;
        }
        try {
            $subscription = self::request()->get('subscriptions', ['customer' => $family->stripe_customer_id, 'status' => 'all', 'limit' => 1])
                ->throw()->json('data.0');
            if ($subscription) {
                self::setStatus($family, $family->stripe_customer_id, $subscription);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Applies a customer.subscription.* event to the family named in its metadata. */
    public static function applySubscription(array $subscription): void
    {
        if ($family = Family::find($subscription['metadata']['family_id'] ?? null)) {
            self::setStatus($family, $subscription['customer'], $subscription);
        }
    }

    /**
     * The one place a family's subscription status changes (checkout redirect + webhook), so the analytics
     * events fire on the TRANSITION wherever it is first seen: the success redirect usually beats the
     * webhook, which then sees active -> active and stays quiet. Filed under the family owner.
     */
    private static function setStatus(Family $family, ?string $customer, array $subscription): void
    {
        $wasPaid = in_array($family->subscription_status, self::PAID, true);
        $wasCanceling = (bool) $family->subscription_canceling;
        // Newer Stripe API versions moved current_period_end from the subscription onto its items.
        $periodEnd = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;
        $family->forceFill([
            'stripe_customer_id' => $customer,
            'subscription_status' => $subscription['status'],
            'subscription_plan' => self::plan($subscription),
            'subscription_tier' => self::tier($subscription),
            'subscription_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'subscription_canceling' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ])->save();
        $isPaid = in_array($subscription['status'], self::PAID, true);

        if ($wasPaid !== $isPaid) {
            $owner = User::find($family->owner_id);
            if ($isPaid) {
                Analytics::track($owner, 'subscription_started', ['plan' => self::plan($subscription)], '/billing');
                AdminPush::send('New subscriber', "{$family->name} subscribed (".self::plan($subscription).').', "/families/{$family->id}");
            } else {
                // canceled / unpaid / incomplete_expired: access ended (a cancel at period end lands here later).
                Analytics::track($owner, 'subscription_canceled', [], '/billing');
                AdminPush::send('Subscription ended', "{$family->name}: now {$subscription['status']}.", "/families/{$family->id}");
            }
        }
        // The customer cancels in Stripe's portal: still paid until the period ends, so this is the moment
        // they decide to leave (the "ended" push above comes weeks later).
        if ($isPaid && ! $wasCanceling && $family->subscription_canceling) {
            AdminPush::send('Subscription canceled', "{$family->name} canceled".($family->subscription_period_end ? ', access ends '.$family->subscription_period_end->toFormattedDateString() : '').'.', "/families/{$family->id}");
        }
    }

    /** 'monthly' | 'yearly' from the subscription's price (either tier, intro or standard), 'unknown' for any other price. */
    private static function plan(array $subscription): string
    {
        $price = $subscription['items']['data'][0]['price']['id'] ?? null;
        foreach (['monthly', 'yearly'] as $plan) {
            $ids = [config("services.stripe.price_{$plan}"), config("services.stripe.price_{$plan}_standard"), config("services.stripe.price_{$plan}_pro"), config("services.stripe.price_{$plan}_pro_standard")];
            if ($price !== null && in_array($price, array_filter($ids), true)) {
                return $plan;
            }
        }

        return 'unknown';
    }

    /** 'pro' when the subscription is on one of the Pro prices, else 'basic' (the only tier before Pro existed). */
    private static function tier(array $subscription): string
    {
        $price = $subscription['items']['data'][0]['price']['id'] ?? null;
        $pro = array_filter([config('services.stripe.price_monthly_pro'), config('services.stripe.price_yearly_pro'), config('services.stripe.price_monthly_pro_standard'), config('services.stripe.price_yearly_pro_standard')]);

        return $price !== null && in_array($price, $pro, true) ? 'pro' : 'basic';
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
