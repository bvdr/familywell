import { router } from '@inertiajs/react';
import { ExpressCheckoutElement, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Appearance, type Stripe, type StripeElementsOptions } from '@stripe/stripe-js';
import { ArrowRight, CreditCard, LoaderCircle, Tag, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The plan checkout built for the onboarding plan step (Stripe Elements: Apple Pay / Google Pay first, card second,
// promo codes, tax on top), moved here so Settings > Billing pays through the very same flow and endpoints
// (onboarding.plan.subscribe / .paid / .promo) instead of the old Stripe-hosted Checkout. Pure extraction: the
// onboarding page renders exactly as before.

export type Plan = 'yearly' | 'monthly';
export type Tier = 'basic' | 'pro';
export type Amounts = { monthly: number; yearly: number };
export type Promo = { id: string; code: string; percent: number | null; amount: number | null; duration: string };
export type Tax = { country: string; percent: number } | null;
export type CheckoutPricing = { intro: boolean; monthly: number; yearly: number; pro?: Amounts };

// Stripe.js loads once per key (it's a script from js.stripe.com, required to be loaded from there).
const stripes: Record<string, Promise<Stripe | null>> = {};
export const stripeFor = (key: string) => (stripes[key] ??= loadStripe(key));

// The Elements iframes in the onboarding's look: paper fields, ink text, 12px corners, Inter.
export const APPEARANCE: Appearance = {
    theme: 'flat',
    variables: {
        colorPrimary: '#121100',
        colorText: '#121100',
        colorBackground: '#F7F5EE',
        // Pill: the Apple Pay / Google Pay button (Express Checkout) takes its corners from here, and it sits on top
        // of our pill "Pay with card". The card fields keep their 12px through the .Input rule below.
        borderRadius: '999px',
        fontFamily: 'Inter, system-ui, sans-serif',
        fontSizeBase: '14px',
    },
    // Inputs at 16px: iOS Safari zooms the page into any field under 16px when it's focused.
    rules: {
        '.Input': { border: '1px solid #E2DED1', fontSize: '16px', borderRadius: '12px' },
        '.Input:focus': { border: '1.5px solid #121100', boxShadow: 'none' },
    },
};

// Deferred intent: Elements knows the amount up front and the subscription is only created on Pay (subscribe).
export const elementsOptions = (total: number): StripeElementsOptions => ({
    // Card only (Apple Pay / Google Pay ride on card in the Express Checkout Element). Not
    // excludedPaymentMethodTypes: ['link']: 'link' isn't a valid value there, Stripe throws and the card form
    // never loads. Link's own prompt is switched off on the Payment Element (wallets.link).
    allowedPaymentMethodTypes: ['card'],
    mode: 'subscription',
    // Today's charge with tax, so Apple Pay / Google Pay show the same total as the page.
    amount: Math.round(total * 100),
    currency: 'usd',
    appearance: APPEARANCE,
    fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap' }],
});

// The checkout's numbers (prices for the tier, intro discount, promo, tax, today's total, renewal) and the summary
// card, shared by the onboarding plan step and Settings > Billing so both always charge and show the same thing.
export function useCheckout({
    plan,
    tier,
    pricing,
    standard,
    tax,
    appliedPromo,
}: {
    plan: Plan;
    tier: Tier;
    pricing: CheckoutPricing;
    standard: { basic: Amounts; pro: Amounts } | null;
    tax: Tax;
    appliedPromo: Promo | null;
}) {
    const t = useT();
    const locale = useLocale();
    // Stripe's prices are USD (services.stripe), so the amounts are formatted as dollars in the reader's locale.
    const usd = (n: number, cents = false) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', minimumFractionDigits: cents || n % 1 ? 2 : 0 }).format(n);

    // The tier's prices: Basic's are the top-level pair, Pro's sit under `pro` (absent = Pro isn't offered).
    const prices: Amounts = (tier === 'pro' && pricing.pro) || pricing;
    // The list price while the intro offer lasts (struck through), else there's nothing to strike.
    const listPrices: Amounts = pricing.intro && standard ? standard[tier] : prices;
    const save = Math.round((1 - prices.yearly / (prices.monthly * 12)) * 100);
    const due = plan === 'yearly' ? prices.yearly : prices.monthly;
    const [promo, setPromo] = useState<Promo | null>(appliedPromo);
    const [promoOpen, setPromoOpen] = useState(false);
    // The promo comes off first and tax is worked out on what's left, the order Stripe's invoice uses.
    const promoOff = promo ? Math.min(due, promo.percent ? Math.round(due * promo.percent) / 100 : (promo.amount ?? 0)) : 0;
    const subtotal = Math.round((due - promoOff) * 100) / 100;
    // Tax on top, rounded to the cent like Stripe's exclusive tax; `total` is what the card is charged today.
    const taxAmount = tax ? Math.round(subtotal * tax.percent) / 100 : 0;
    const total = Math.round((subtotal + taxAmount) * 100) / 100;
    const taxCountry = tax ? (new Intl.DisplayNames([locale], { type: 'region' }).of(tax.country) ?? tax.country) : '';
    // "$45" or "$45 + VAT" for the renewal lines (renewals are taxed the same way).
    // A "forever" code keeps the discount on every renewal; a one-off (or months-long) one renews at the plan price.
    const renewBase = promo?.duration === 'forever' ? subtotal : due;
    const renewAmount = tax ? t('onboarding.plan_plus_tax', { amount: usd(renewBase) }) : usd(renewBase);
    const removePromo = () => {
        setPromo(null);
        void fetch(route('onboarding.plan.promo.remove'), { method: 'DELETE', headers: { 'X-XSRF-TOKEN': xsrf() }, credentials: 'same-origin' });
    };
    // "Add promo code", or the applied code as a green chip with its remove button (design 06 / D09).
    const promoControl = promo ? (
        <span className="bg-ok-soft text-ok-ink inline-flex h-6 items-center gap-[5px] self-start rounded-full pr-1.5 pl-2 font-mono text-[11px] font-semibold">
            <Tag className="size-3" />
            {promo.code} · −{promo.percent ? `${promo.percent}%` : usd(promo.amount ?? 0)}
            <button type="button" onClick={removePromo} aria-label={t('onboarding.promo_remove')}>
                <X className="size-3.5" />
            </button>
        </span>
    ) : (
        <button
            type="button"
            onClick={() => setPromoOpen(true)}
            className="text-ink-2 inline-flex items-center gap-[5px] self-start text-[12.5px] font-semibold"
        >
            <Tag className="size-[13px]" />
            {t('onboarding.promo_add')}
        </button>
    );
    const list = plan === 'yearly' ? listPrices.yearly : listPrices.monthly;
    const perMonth = (p: Plan, a: Amounts) => (p === 'yearly' ? a.yearly / 12 : a.monthly);
    const renews = new Date();
    if (plan === 'yearly') renews.setFullYear(renews.getFullYear() + 1);
    else renews.setMonth(renews.getMonth() + 1);
    const renewsText = renews.toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
    const tierName = t(`onboarding.tier_${tier}`);

    const summary = (
        <div className="bg-paper-2 flex flex-col gap-2 rounded-2xl p-4 text-[13px]">
            <div className="flex justify-between">
                <span>{t(`onboarding.plan_line_${plan}`, { tier: tierName })}</span>
                <span className="font-mono">{usd(list, true)}</span>
            </div>
            {list > due && (
                <div className="flex justify-between">
                    <span>{t('onboarding.plan_intro_line')}</span>
                    <span className="text-ok-ink font-mono">-{usd(list - due, true)}</span>
                </div>
            )}
            <div className="flex items-center justify-between">
                {promoControl}
                {promo && <span className="text-ok-ink font-mono">-{usd(promoOff, true)}</span>}
            </div>
            {tax && (
                <div className="flex justify-between">
                    <span>{t('onboarding.plan_tax_line', { country: taxCountry, rate: tax.percent })}</span>
                    <span className="font-mono">{usd(taxAmount, true)}</span>
                </div>
            )}
            <div className="border-line flex items-baseline justify-between border-t pt-2">
                <span className="text-[15px] font-bold">{t('onboarding.plan_due')}</span>
                <span className="text-[17px] font-bold">{usd(total, true)}</span>
            </div>
            <span className="text-stone text-xs">{t('onboarding.plan_renews', { date: renewsText, amount: renewAmount })}</span>
        </div>
    );

    const payLabel = t('onboarding.plan_pay', { amount: usd(total, total % 1 !== 0) });

    return {
        usd,
        prices,
        listPrices,
        save,
        due,
        promo,
        setPromo,
        promoOpen,
        setPromoOpen,
        taxAmount,
        total,
        renewAmount,
        list,
        perMonth,
        renewsText,
        tierName,
        summary,
        payLabel,
        promoControl,
    };
}

// Paying: Apple Pay / Google Pay (Express Checkout Element) and card (Payment Element). Both go through pay():
// collect + validate the details, have the server create the incomplete subscription (subscribe), confirm its first
// invoice here, then sync and finish (paid). A card that needs 3-D Secure redirects to Stripe and comes back to paid.
export function Pay({
    variant,
    plan,
    tier,
    email,
    label,
    busy,
    setBusy,
}: {
    variant: 'phone' | 'desk';
    plan: Plan;
    tier: Tier;
    email: string;
    label: string;
    busy: boolean;
    setBusy: (b: boolean) => void;
}) {
    const t = useT();
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState<string | null>(null);
    const [sheet, setSheet] = useState(false);
    const [express, setExpress] = useState(false);
    // Homer thinks while Stripe loads.
    useEffect(() => {
        if (!stripe) reactHomer('thinking');
    }, [stripe]);

    const fail = (message?: string) => {
        setError(message ?? t('onboarding.plan_error'));
        setBusy(false);
        reactHomer('error');
    };

    const pay = async () => {
        if (!stripe || !elements) return;
        setBusy(true);
        setError(null);
        const { error: invalid } = await elements.submit();
        if (invalid) return fail(invalid.message);
        const res = await fetch(route('onboarding.plan.subscribe'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ plan, tier }),
        });
        if (!res.ok) return fail();
        const { subscription, clientSecret } = (await res.json()) as { subscription: string; clientSecret: string };
        const back = route('onboarding.plan.paid', { subscription });
        const { error: declined } = await stripe.confirmPayment({
            elements,
            clientSecret,
            confirmParams: { return_url: back, payment_method_data: { billing_details: { email } } },
            redirect: 'if_required',
        });
        if (declined) return fail(declined.message);
        reactHomer('celebrate');
        router.visit(back);
    };

    const expressButton = (
        <ExpressCheckoutElement
            onReady={({ availablePaymentMethods }) => setExpress(!!availablePaymentMethods)}
            onConfirm={() => void pay()}
            options={{
                buttonHeight: 49, // the height of our cta buttons (size="cta"), so the two stack evenly
                buttonTheme: { applePay: 'black', googlePay: 'black' },
                buttonType: { applePay: 'plain', googlePay: 'plain' },
                paymentMethods: { link: 'never' },
            }}
        />
    );
    const payButton = (
        <Button variant="sun" size="cta" className="w-full" disabled={busy || !stripe} onClick={() => void pay()}>
            {label}
            {busy ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
        </Button>
    );
    const errorLine = error && (
        <p className="text-[13px] text-[#C2381E]" role="alert">
            {error}
        </p>
    );

    if (variant === 'phone') {
        return (
            <div className="flex flex-col gap-3">
                {expressButton}
                <Button variant="sun" size="cta" className="w-full" disabled={busy || !stripe} onClick={() => setSheet(true)}>
                    {t('onboarding.plan_card')}
                    <CreditCard />
                </Button>
                {!sheet && errorLine}
                <ResponsiveModal
                    open={sheet}
                    onOpenChange={setSheet}
                    title={
                        <span className="flex items-center justify-between gap-3 pr-2">
                            {t('onboarding.plan_card')}
                            <span className="text-stone flex items-center gap-1.5 font-sans text-xs font-normal">
                                {t('onboarding.plan_powered_by')}
                                <StripeMark />
                            </span>
                        </span>
                    }
                    className="bg-paper text-ink"
                    footer={payButton}
                >
                    <div className="flex flex-col gap-3">
                        <PaymentElement options={{ layout: 'tabs', wallets: { applePay: 'never', googlePay: 'never', link: 'never' } }} />
                        {errorLine}
                    </div>
                </ResponsiveModal>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {expressButton}
            {express && (
                <p className="text-stone flex items-center gap-3 text-xs">
                    <span className="bg-line h-px flex-1" />
                    {t('onboarding.plan_or_card')}
                    <span className="bg-line h-px flex-1" />
                </p>
            )}
            <PaymentElement options={{ layout: 'tabs', wallets: { applePay: 'never', googlePay: 'never', link: 'never' } }} />
            {errorLine}
            {payButton}
        </div>
    );
}

// The cards and wallets that work here, as small logo tiles under the pay button (design 06 / D09: the brands'
// own marks, paths copied from the design file).
const tile = 'border-line flex h-[26px] w-10 items-center justify-center rounded-md border bg-white';
export function Brands() {
    return (
        <ul className="flex flex-wrap justify-center gap-1.5" aria-hidden>
            <li className={tile}>
                <svg viewBox="0 8.2 24 7.6" className="h-[9px] w-[27px]">
                    <path
                        fill="#1A1F71"
                        d="M9.112 8.262l-3.142 7.496h-2.05l-1.546-5.983c-0.094-0.368-0.175-0.503-0.461-0.658-0.466-0.253-1.236-0.49-1.913-0.638l0.046-0.217h3.3a0.904 0.904 0 0 1 0.894 0.764l0.817 4.338 2.018-5.102z m8.033 5.049c0.008-1.979-2.736-2.088-2.717-2.972 0.006-0.269 0.262-0.555 0.822-0.628a3.66 3.66 0 0 1 1.913 0.336l0.34-1.59a5.207 5.207 0 0 0-1.814-0.333c-1.917 0-3.266 1.02-3.278 2.479-0.012 1.079 0.963 1.68 1.698 2.04 0.756 0.367 1.01 0.603 1.006 0.931-0.005 0.504-0.602 0.725-1.16 0.734-0.975 0.015-1.54-0.263-1.992-0.473l-0.351 1.642c0.453 0.208 1.289 0.39 2.156 0.398 2.037 0 3.37-1.006 3.377-2.564m5.061 2.447h1.794l-1.565-7.496h-1.656a0.883 0.883 0 0 0-0.826 0.55l-2.909 6.946h2.036l0.405-1.12h2.488z m-2.163-2.656l1.02-2.815 0.588 2.815z m-8.16-4.84l-1.603 7.496h-1.94l1.605-7.496z"
                    />
                </svg>
            </li>
            <li className={tile}>
                <svg viewBox="0 0 24 15" className="h-[15px] w-6">
                    <circle cx="7.5" cy="7.5" r="7.5" fill="#EB001B" />
                    <circle cx="16.5" cy="7.5" r="7.5" fill="#F79E1B" fillOpacity="0.9" />
                </svg>
            </li>
            <li className="flex h-[26px] w-10 items-center justify-center rounded-md bg-[#2E77BC] text-[9px] font-extrabold tracking-[0.4px] text-white">
                AMEX
            </li>
            <li className={cn(tile, 'gap-px')}>
                <svg viewBox="3 0 17.8 24" className="mb-[2.3px] h-[9.7px] w-[7.2px]">
                    <path d="M12.152 6.896c-0.948 0-2.415-1.078-3.96-1.04-2.04 0.027-3.91 1.183-4.961 3.014-2.117 3.675-0.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-0.065 2.09-0.987 3.935-0.987 1.831 0 2.35 0.987 3.96 0.948 1.637-0.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-0.039-0.013-3.182-1.221-3.22-4.857-0.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-0.156-3.675 1.09-4.61 1.09z m3.378-3.066c0.843-1.012 1.4-2.427 1.245-3.83-1.207 0.052-2.662 0.805-3.532 1.818-0.78 0.896-1.454 2.338-1.273 3.714 1.338 0.104 2.715-0.688 3.559-1.701" />
                </svg>
                <span className="text-[10.5px] font-semibold text-black">Pay</span>
            </li>
            <li className={tile}>
                <svg viewBox="0 7.2 24 10.1" className="h-[11px] w-[27px]">
                    <path
                        fill="#3C4043"
                        d="M3.963 7.235a3.963 3.963 0 0 0-3.541 2.184 3.963 3.963 0 0 0 0 3.559 3.963 3.963 0 0 0 3.541 2.184c1.07 0 1.97-0.352 2.627-0.957 0.748-0.69 1.18-1.71 1.18-2.916a4.722 4.722 0 0 0-0.07-0.806h-3.736v1.526h2.14a1.835 1.835 0 0 1-0.79 1.205c-0.356 0.241-0.814 0.379-1.35 0.379-1.034 0-1.911-0.697-2.225-1.636a2.375 2.375 0 0 1 0-1.517c0.314-0.94 1.191-1.636 2.225-1.636a2.152 2.152 0 0 1 1.52 0.594l1.132-1.13a3.808 3.808 0 0 0-2.652-1.033z m6.501 0.55v6.9h0.886v-2.795h1.465c0.603 0 1.11-0.196 1.522-0.588a1.911 1.911 0 0 0 0.635-1.464 1.92 1.92 0 0 0-0.635-1.456 2.125 2.125 0 0 0-1.522-0.598z m2.427 0.85a1.156 1.156 0 0 1 0.823 0.365 1.176 1.176 0 0 1 0 1.686 1.171 1.171 0 0 1-0.877 0.357h-1.487v-2.408h1.487a1.156 1.156 0 0 1 0.054 0z m4.124 1.175c-0.842 0-1.477 0.308-1.907 0.925l0.781 0.491c0.288-0.417 0.68-0.626 1.175-0.626a1.255 1.255 0 0 1 0.856 0.323 1.009 1.009 0 0 1 0.366 0.785v0.202c-0.34-0.193-0.774-0.289-1.3-0.289-0.617 0-1.11 0.145-1.479 0.434-0.37 0.288-0.554 0.677-0.554 1.165a1.476 1.476 0 0 0 0.525 1.156c0.35 0.308 0.785 0.463 1.305 0.463 0.61 0 1.098-0.27 1.465-0.81h0.038v0.655h0.848v-2.909c0-0.61-0.19-1.09-0.568-1.44-0.38-0.35-0.896-0.525-1.551-0.525z m2.263 0.154l1.946 4.422-1.098 2.38h0.915l2.959-6.803h-0.965l-1.368 3.391h-0.02l-1.406-3.39z m-2.146 2.368c0.494 0 0.88 0.11 1.156 0.33 0 0.372-0.147 0.696-0.44 0.973a1.413 1.413 0 0 1-0.997 0.414 1.081 1.081 0 0 1-0.69-0.232 0.708 0.708 0 0 1-0.293-0.578c0-0.257 0.12-0.47 0.363-0.647 0.24-0.173 0.54-0.26 0.9-0.26z"
                    />
                </svg>
            </li>
        </ul>
    );
}

// "Secure checkout by [S] Stripe": Stripe's mark and wordmark colour, as in the design.
export function StripeMark() {
    return (
        <span className="inline-flex items-center gap-1 text-[12.5px] font-extrabold tracking-[-0.2px] text-[#635BFF]">
            <span className="flex size-4 items-center justify-center rounded bg-[#635BFF]">
                <svg viewBox="0 0 24 24" className="size-[9px]" aria-hidden>
                    <path
                        fill="#FFFFFF"
                        d="M13.976 9.15c-2.172-0.806-3.356-1.426-3.356-2.409 0-0.831 0.683-1.305 1.901-1.305 2.227 0 4.515 0.858 6.09 1.631l0.89-5.494c-1.249-0.598-3.804-1.573-7.336-1.573-2.498 0-4.576 0.654-6.061 1.872-1.544 1.275-2.347 3.12-2.347 5.346 0 4.039 2.467 5.76 6.476 7.219 2.585 0.92 3.445 1.574 3.445 2.583 0 0.98-0.84 1.545-2.354 1.545-1.875 0-4.965-0.921-6.99-2.109l-0.9 5.555c1.741 0.979 4.951 1.989 8.28 1.989 2.641 0 4.843-0.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.594-7.305h0.003z"
                    />
                </svg>
            </span>
            Stripe
        </span>
    );
}

// Design 06a: a small sheet (keyboard up on phones) to type a code. Checked against Stripe right away; a bad or
// expired code is one line under the field and the sheet stays open.
export function PromoSheet({ open, onOpenChange, onApplied }: { open: boolean; onOpenChange: (v: boolean) => void; onApplied: (p: Promo) => void }) {
    const t = useT();
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const field = useRef<HTMLInputElement>(null);

    const apply = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!code.trim() || busy) return;
        setBusy(true);
        setError(null);
        const res = await fetch(route('onboarding.plan.promo'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ code }),
        }).catch(() => null);
        setBusy(false);
        if (res?.ok) {
            setCode('');
            onApplied((await res.json()) as Promo);
            return;
        }
        setError(t('onboarding.promo_invalid'));
        reactHomer('no');
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('onboarding.promo_title')}
            className="text-ink bg-white"
            autoFocusRef={field}
        >
            <form onSubmit={apply} className="flex flex-col gap-2.5 pb-2">
                <div className="flex items-center gap-2">
                    <div className="relative flex-1">
                        <Tag className="text-stone pointer-events-none absolute top-1/2 left-3.5 size-[18px] -translate-y-1/2" />
                        <Input
                            ref={field}
                            value={code}
                            onChange={(e) => setCode(e.target.value.toUpperCase())}
                            autoCapitalize="characters"
                            autoComplete="off"
                            spellCheck={false}
                            aria-label={t('onboarding.promo_title')}
                            aria-invalid={!!error}
                            // 16px: iOS zooms into smaller fields.
                            className="border-ink focus-visible:border-ink h-[52px] rounded-[14px] border-2 bg-white pl-11 font-mono text-base font-semibold tracking-[1px] focus-visible:ring-0 focus-visible:ring-offset-0"
                        />
                    </div>
                    <Button type="submit" variant="ink" disabled={busy || !code.trim()} className="h-[52px] px-5 text-[15px]">
                        {busy ? <LoaderCircle className="animate-spin" /> : t('onboarding.promo_apply')}
                    </Button>
                </div>
                {error ? (
                    <p className="text-[12.5px] text-[#C2381E]" role="alert">
                        {error}
                    </p>
                ) : (
                    <p className="text-stone text-[12.5px] leading-[1.4]">{t('onboarding.promo_hint')}</p>
                )}
            </form>
        </ResponsiveModal>
    );
}

export function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}
