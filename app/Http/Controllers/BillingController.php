<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\AdminPush;
use App\Support\Analytics;
use App\Support\Billing\Billing;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

// Settings > Billing: the paywall page (locked families land here) and, once subscribed, the link to
// Stripe's customer portal. Every route 404s when Stripe isn't configured (self-hosted installs).
class BillingController extends Controller
{
    public function show(Request $request)
    {
        $family = $this->family();
        if ($sessionId = $request->query('session_id')) {
            Billing::syncCheckoutSession($family, (string) $sessionId);
            // Funnel step in the browser's visit; the subscription_started count stays server-side (Billing).
            if (Billing::isPaid($family)) {
                Analytics::queue('checkout_completed');
            }

            return to_route('billing.show');
        }

        Billing::backfillPlan($family);

        return Inertia::render('settings/billing', [
            'family' => ['name' => $family->name],
            // The Plan card: monthly / yearly (null = not known yet), and when the period ends + whether
            // it renews or ends then (cancel at period end). Kept fresh by Billing::setStatus().
            'plan' => $family->subscription_plan,
            'periodEnd' => $family->subscription_period_end?->toIso8601String(),
            'canceling' => (bool) $family->subscription_canceling,
            'subscribed' => Billing::isPaid($family),
            // Still in the free period (no paywall yet): the plans page offers a way back into the app.
            'locked' => ! Billing::hasAccess($family),
            // Comped by an admin: the page shows "Lifetime (VIP)" with no checkout or portal prompts.
            'lifetime' => Billing::isLifetime($family),
            'status' => $family->subscription_status,
            'hasCustomer' => (bool) $family->stripe_customer_id,
            'canManage' => Gate::allows('manageBilling', $family),
            // Today's price for new subscribers (intro while spots last), shown on the plan picker.
            'pricing' => Billing::pricing(),
            // The rest of what the onboarding checkout (components/plan-checkout.tsx) needs, the same values
            // OnboardingController::plan passes: the page pays through that flow, not a checkout of its own.
            // Only for an unpaid family: a paying one sees its plan card and no checkout (and no tax lookup).
            ...(Billing::isPaid($family) ? [] : [
                'standard' => ['basic' => config('familywell.pricing.standard'), 'pro' => config('familywell.pricing.pro.standard')],
                'tiers' => config('familywell.tiers'),
                'stripeKey' => config('services.stripe.key'),
                'tax' => Billing::taxRate((string) ($request->header('CF-Connecting-IP') ?: $request->ip())),
                // Applied on the checkout (POST onboarding.plan.promo), kept in the session until paid or removed.
                'promo' => $request->session()->get('onboarding.promo'),
                'email' => $request->user()->email,
            ]),
        ]);
    }

    public function checkout(Request $request)
    {
        $family = $this->family();
        $this->authorize('manageBilling', $family);
        $plan = $request->validate(['plan' => ['required', 'in:monthly,yearly']])['plan'];
        abort_if(Billing::isLifetime($family), 409, 'This family has lifetime access.'); // never charge a comped family

        $url = Billing::checkoutUrl($family, $request->user()->email, $plan);
        // After the Stripe session exists (a Stripe error throws first, so no push for a checkout that never opened).
        AdminPush::send('Checkout started', "{$family->name} opened the {$plan} checkout ({$request->user()->name}).", "/families/{$family->id}");

        // Inertia::location: a full-page redirect off-site (Stripe's hosted Checkout), not an XHR visit.
        return Inertia::location($url);
    }

    public function portal()
    {
        $family = $this->family();
        $this->authorize('manageBilling', $family);
        abort_unless($family->stripe_customer_id, 404);

        return Inertia::location(Billing::portalUrl($family));
    }

    // Stripe -> us. Unauthenticated + CSRF-exempt (bootstrap/app.php); trust comes from the signature.
    public function webhook(Request $request)
    {
        if (! Billing::validSignature($request->getContent(), $request->header('Stripe-Signature'))) {
            // Logged so a misconfigured STRIPE_WEBHOOK_SECRET shows up in the app logs instead of only as
            // "pending" deliveries in Stripe. Never logs the payload or the signature.
            Log::warning('Stripe webhook rejected: signature check failed', ['has_header' => $request->hasHeader('Stripe-Signature')]);
            abort(400);
        }

        $event = $request->json()->all();
        if (str_starts_with($event['type'] ?? '', 'customer.subscription.')) {
            Billing::applySubscription($event['data']['object']);
        }

        return response()->noContent();
    }

    private function family(): Family
    {
        abort_unless(Billing::enabled(), 404);

        return Family::findOrFail(CurrentFamily::id());
    }
}
