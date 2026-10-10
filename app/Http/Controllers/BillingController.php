<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Support\Billing\Billing;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

            return to_route('billing.show');
        }

        return Inertia::render('settings/billing', [
            'family' => ['name' => $family->name],
            'subscribed' => Billing::hasAccess($family),
            'status' => $family->subscription_status,
            'hasCustomer' => (bool) $family->stripe_customer_id,
            'canManage' => Gate::allows('manageBilling', $family),
        ]);
    }

    public function checkout(Request $request)
    {
        $family = $this->family();
        $this->authorize('manageBilling', $family);
        $plan = $request->validate(['plan' => ['required', 'in:monthly,yearly']])['plan'];

        // Inertia::location: a full-page redirect off-site (Stripe's hosted Checkout), not an XHR visit.
        return Inertia::location(Billing::checkoutUrl($family, $request->user()->email, $plan));
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
        abort_unless(Billing::validSignature($request->getContent(), $request->header('Stripe-Signature')), 400);

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
