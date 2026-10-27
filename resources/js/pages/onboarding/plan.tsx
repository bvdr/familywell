import { Head, router } from '@inertiajs/react';
import { Elements, ExpressCheckoutElement, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Appearance, type Stripe } from '@stripe/stripe-js';
import { ArrowRight, Check, CreditCard, Github, LoaderCircle, Lock } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Homer, reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Plan = 'yearly' | 'monthly';
type Tier = 'basic' | 'pro';
type Amounts = { monthly: number; yearly: number };
type Props = {
    family: { name: string };
    invited: string[];
    // Billing::pricing(): today's whole-dollar prices (intro while spots last) per tier; `pro` only when Pro is on offer.
    pricing: { intro: boolean; spotsLeft: number; spotsTotal: number; monthly: number; yearly: number; pro?: Amounts };
    // The list prices per tier, for the strike-through and the "Discount" line while the intro offer lasts.
    standard: { basic: Amounts; pro: Amounts } | null;
    // What each tier says it includes ("1 GB · 30 AI points"); display copy, see config familywell.tiers.
    tiers: Record<Tier, { storage_gb: number; ai_points: number }> | null;
    // The publishable key (STRIPE_KEY); the secret stays on the server.
    stripeKey: string | null;
    // VAT / sales tax where the visitor is (Billing::taxRate, from their IP like Stripe Checkout), added on top of
    // the price; null = nothing to add.
    tax: { country: string; percent: number } | null;
    email: string;
    failed: boolean;
    paid: boolean;
    // Where the celebration goes next (the "home is ready" recap, or the app for a family paying again).
    next: string | null;
    selfHostingUrl: string | null;
};

// The tablet / desktop list under the plans; `{storage}` and `{ai}` rows take the tier's numbers.
const INCLUDED = ['plan_inc_modules', 'plan_inc_members', 'storage', 'ai', 'plan_inc_open', 'plan_inc_ads'] as const;
// The phone's shorter list (design 06).
const INCLUDED_PHONE = ['plan_inc_short', 'storage', 'ai', 'plan_inc_cancel'] as const;

// Stripe.js loads once per key (it's a script from js.stripe.com, required to be loaded from there).
const stripes: Record<string, Promise<Stripe | null>> = {};
const stripeFor = (key: string) => (stripes[key] ??= loadStripe(key));

// The Elements iframes in the onboarding's look: paper fields, ink text, 12px corners, Inter.
const APPEARANCE: Appearance = {
    theme: 'flat',
    variables: {
        colorPrimary: '#121100',
        colorText: '#121100',
        colorBackground: '#F7F5EE',
        borderRadius: '12px',
        fontFamily: 'Inter, system-ui, sans-serif',
        fontSizeBase: '14px',
    },
    rules: { '.Input': { border: '1px solid #E2DED1' }, '.Input:focus': { border: '1.5px solid #121100', boxShadow: 'none' } },
};

// Phone layout (< md): Apple / Google Pay + "Pay with card" in a sheet. Tablet / desktop: the checkout card inline.
// Only one is mounted, since a Payment Element can exist once per Elements group.
function usePhone(): boolean {
    const [phone, setPhone] = useState(false);
    useEffect(() => {
        const mq = window.matchMedia('(max-width: 767px)');
        const on = () => setPhone(mq.matches);
        on();
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, []);
    return phone;
}

// Plan & pay (design 06 / T09 / D09): right after the notifications, before the "home is ready" recap and the tour, and
// the only way into the app (hard paywall). A Basic / Pro switch (Pro only where it's on offer), yearly preselected,
// the total in plain sight, paid right here with Stripe Elements (Apple Pay / Google Pay first, card second).
// Prices come from Billing::pricing() (config/familywell.php), never from here.
export default function PlanStep({ family, invited, pricing, standard, tiers, stripeKey, tax, email, failed, paid, next, selfHostingUrl }: Props) {
    const t = useT();
    const locale = useLocale();
    const [plan, setPlan] = useState<Plan>('yearly');
    const [tier, setTier] = useState<Tier>('basic');
    const [busy, setBusy] = useState(false);
    // Stripe's prices are USD (services.stripe), so the amounts are formatted as dollars in the reader's locale.
    const usd = (n: number, cents = false) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', minimumFractionDigits: cents || n % 1 ? 2 : 0 }).format(n);

    useEffect(() => {
        if (paid) {
            reactHomer('celebrate');
            const id = window.setTimeout(() => router.visit(next ?? route('dashboard')), 1800);
            return () => window.clearTimeout(id);
        }
        if (failed) reactHomer('error');
    }, [paid, failed, next]);

    // The tier's prices: Basic's are the top-level pair, Pro's sit under `pro` (absent = Pro isn't offered).
    const prices: Amounts = (tier === 'pro' && pricing.pro) || pricing;
    // The list price while the intro offer lasts (struck through), else there's nothing to strike.
    const listPrices: Amounts = pricing.intro && standard ? standard[tier] : prices;
    const save = Math.round((1 - prices.yearly / (prices.monthly * 12)) * 100);
    const due = plan === 'yearly' ? prices.yearly : prices.monthly;
    // Tax on top, rounded to the cent like Stripe's exclusive tax; `total` is what the card is charged today.
    const taxAmount = tax ? Math.round(due * tax.percent) / 100 : 0;
    const total = Math.round((due + taxAmount) * 100) / 100;
    const taxCountry = tax ? (new Intl.DisplayNames([locale], { type: 'region' }).of(tax.country) ?? tax.country) : '';
    // "$45" or "$45 + VAT" for the renewal lines (renewals are taxed the same way).
    const renewAmount = tax ? t('onboarding.plan_plus_tax', { amount: usd(due) }) : usd(due);
    const list = plan === 'yearly' ? listPrices.yearly : listPrices.monthly;
    const perMonth = (p: Plan, a: Amounts) => (p === 'yearly' ? a.yearly / 12 : a.monthly);
    const renews = new Date();
    if (plan === 'yearly') renews.setFullYear(renews.getFullYear() + 1);
    else renews.setMonth(renews.getMonth() + 1);
    const renewsText = renews.toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
    const tierName = t(`onboarding.tier_${tier}`);
    const tierSub = (x: Tier) => (tiers ? t('onboarding.plan_tier_sub', { gb: tiers[x].storage_gb, ai: tiers[x].ai_points }) : '');
    // One row of "what's included": a translated line, or the tier's storage / AI numbers.
    const includedLine = (k: string) =>
        k === 'storage'
            ? t('onboarding.plan_inc_storage', { gb: tiers?.[tier].storage_gb ?? '' })
            : k === 'ai'
              ? t('onboarding.plan_inc_ai', { n: tiers?.[tier].ai_points ?? '' })
              : t(`onboarding.${k}`);

    const phone = usePhone();
    const stripePromise = useMemo(() => (stripeKey ? stripeFor(stripeKey) : null), [stripeKey]);

    if (paid) {
        return (
            <div className="bg-sun text-ink flex min-h-dvh flex-col items-center justify-center gap-4 p-8 text-center">
                <Head title={t('onboarding.plan_paid')} />
                <Homer face="happy" className="size-[200px]" />
                <h1 className="font-display text-[40px] leading-none font-bold tracking-[-1.4px]">{t('onboarding.plan_paid')}</h1>
                <p className="text-ink-2 text-base">{t('onboarding.plan_paid_sub', { name: family.name })}</p>
            </div>
        );
    }

    const payLine = invited.length
        ? t('onboarding.plan_payer', { name: family.name, names: invited.join(` ${t('onboarding.and')} `) })
        : t('onboarding.plan_payer_solo', { name: family.name });
    const payLineShort = invited.length
        ? t('onboarding.plan_payer_short', { names: invited.join(` ${t('onboarding.and')} `) })
        : t('onboarding.plan_payer_short_solo');

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

    return (
        // Deferred intent: Elements knows the amount up front and the subscription is only created on Pay (subscribe).
        <Elements
            stripe={stripePromise}
            options={{
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
            }}
        >
            <Head title={t('onboarding.plan_title', { name: family.name })} />
            <OnboardingLayout
                step={5}
                wide
                back={route('onboarding.notifications')}
                homer={{ face: 'happy' }}
                title={t('onboarding.plan_title', { name: family.name })}
                sub={t(pricing.pro ? 'onboarding.plan_sub' : 'onboarding.plan_sub_one')}
            >
                <div className="grid grid-cols-1 gap-6 px-5 pb-6 md:grid-cols-2 md:gap-x-5 md:px-0 lg:grid-cols-[minmax(0,430px)_372px] lg:grid-rows-[auto_1fr] lg:gap-x-10">
                    {/* Tier, plans and what's included (left); checkout (right on tablet / desktop). */}
                    <div className="flex flex-col gap-5 md:col-span-2 lg:col-span-1">
                        {failed && <p className="bg-ok-soft text-ink rounded-xl px-3.5 py-2.5 text-[13px]">{t('onboarding.plan_canceled')}</p>}

                        {/* Basic | Pro: only where Pro is on offer (its Stripe prices exist). */}
                        {pricing.pro && tiers && (
                            <div className="bg-paper-2 grid grid-cols-2 rounded-full p-1" role="tablist" aria-label={t('onboarding.plan_tiers')}>
                                {(['basic', 'pro'] as const).map((x) => (
                                    <button
                                        key={x}
                                        type="button"
                                        role="tab"
                                        aria-selected={tier === x}
                                        onClick={() => setTier(x)}
                                        className={cn(
                                            'flex items-baseline justify-center gap-2 rounded-full px-2 py-2 text-[13px]',
                                            tier === x ? 'bg-white font-bold' : 'text-stone font-medium',
                                        )}
                                    >
                                        {t(`onboarding.tier_${x}`)}
                                        <span className="text-stone hidden font-mono text-[10.5px] font-medium min-[420px]:inline">{tierSub(x)}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-2.5" role="radiogroup" aria-label={t('onboarding.plan_choose')}>
                            {(['yearly', 'monthly'] as const).map((p) => {
                                const on = plan === p;
                                const price = perMonth(p, prices);
                                const struck = perMonth(p, listPrices);
                                return (
                                    <button
                                        key={p}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        onClick={() => setPlan(p)}
                                        className={cn(
                                            'relative flex flex-col gap-1.5 rounded-[18px] border p-3.5 text-left md:p-4',
                                            on ? 'border-ink bg-sun-soft border-[1.5px]' : 'border-line bg-white',
                                        )}
                                    >
                                        <span className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                                    on ? 'border-ink bg-ink text-sun' : 'border-stone/40',
                                                )}
                                            >
                                                {on && <Check className="size-3" strokeWidth={3.5} />}
                                            </span>
                                            <span className="text-[15px] font-bold">{t(`onboarding.plan_${p}`)}</span>
                                            {p === 'yearly' && save > 0 && (
                                                <span className="bg-ink text-sun absolute -top-2.5 right-3 rounded-full px-2 py-0.5 text-[11px] font-bold md:static md:ml-auto">
                                                    {t('onboarding.plan_save', { n: save })}
                                                </span>
                                            )}
                                        </span>
                                        {struck > price && <span className="text-stone text-[13px] line-through">{usd(struck)}</span>}
                                        <span className="flex items-baseline gap-1.5">
                                            <span className="font-display text-[30px] leading-none font-bold tracking-[-1px] md:text-[36px]">
                                                {usd(price)}
                                            </span>
                                            <span className="text-stone text-[13px]">{t('onboarding.plan_per_month')}</span>
                                        </span>
                                        <span className="text-ink-2 text-xs">
                                            {p === 'yearly'
                                                ? t('onboarding.plan_billed_yearly', { amount: usd(prices.yearly) })
                                                : t('onboarding.plan_billed_monthly')}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex flex-col gap-2.5 lg:row-start-2">
                        <span className="text-stone hidden font-mono text-[11px] font-semibold tracking-[0.8px] uppercase md:block">
                            {t('onboarding.plan_included_in', { tier: tierName })}
                        </span>
                        {/* Phone: the short list; tablet / desktop: the full one. */}
                        {[
                            { rows: INCLUDED_PHONE, cls: 'md:hidden' },
                            { rows: INCLUDED, cls: 'hidden md:flex' },
                        ].map(({ rows, cls }) => (
                            <ul key={cls} className={cn('flex-col gap-2', cls === 'md:hidden' ? 'flex md:hidden' : cls)}>
                                {rows.map((k) => (
                                    <li key={k} className="flex items-center gap-2.5 text-[13.5px]">
                                        <span className="bg-ok-soft text-ok-ink flex size-[18px] shrink-0 items-center justify-center rounded-full">
                                            <Check className="size-3" strokeWidth={3} />
                                        </span>
                                        {includedLine(k)}
                                    </li>
                                ))}
                            </ul>
                        ))}
                        <div className="hidden flex-1 items-end md:flex">
                            <Homer face="happy" view="mirror" className="size-[110px] lg:hidden" />
                        </div>
                        {selfHostingUrl && (
                            <p className="text-stone border-line mt-2 hidden items-center gap-2 border-t pt-4 text-[13px] md:flex">
                                <Github className="size-4 shrink-0" />
                                <span>
                                    {t('onboarding.plan_selfhost')}{' '}
                                    <a href={selfHostingUrl} target="_blank" rel="noopener" className="text-ink font-semibold">
                                        {t('onboarding.plan_selfhost_link')}
                                    </a>
                                </span>
                            </p>
                        )}
                    </div>

                    {/* Phone: the total with Homer, then pay. */}
                    <div className="flex flex-col gap-3 md:hidden">
                        <div className="border-line flex items-center gap-3 rounded-2xl border bg-white p-3.5">
                            {/* Right-hand panel Homer faces left by default; here he sits left of the total, so mirror him. */}
                            <Homer face="happy" view="mirror" className="size-11" />
                            <span className="flex-1">
                                <span className="block text-sm font-semibold">{t('onboarding.plan_due')}</span>
                                <span className="text-stone block text-xs">
                                    {t('onboarding.plan_renews_short', { date: renewsText, amount: renewAmount })}
                                </span>
                                {tax && (
                                    <span className="text-stone block text-xs">
                                        {t('onboarding.plan_tax_incl', { amount: usd(taxAmount, true), rate: tax.percent })}
                                    </span>
                                )}
                            </span>
                            <span className="text-right">
                                {list > due && (
                                    <span className="text-stone block text-xs line-through">
                                        {usd(tax ? Math.round(list * (100 + tax.percent)) / 100 : list, true)}
                                    </span>
                                )}
                                <span className="font-display block text-2xl font-bold">{usd(total, true)}</span>
                            </span>
                        </div>
                        {phone && <Pay variant="phone" plan={plan} tier={tier} email={email} label={payLabel} busy={busy} setBusy={setBusy} />}
                        <p className="text-stone text-center text-xs">{payLineShort}</p>
                        <p className="text-stone flex items-center justify-center gap-1.5 text-xs">
                            <Lock className="size-3.5" />
                            {t('onboarding.plan_secure_by')}
                            <span className="text-ink font-bold">Stripe</span>
                        </p>
                        <Brands />
                    </div>

                    {/* Tablet / desktop: the checkout card. */}
                    <div className="border-line hidden flex-col gap-4 self-start rounded-[22px] border bg-white p-5 md:flex lg:col-start-2 lg:row-span-2 lg:row-start-1">
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-2 text-[15px] font-bold">
                                <Lock className="size-4" />
                                {t('onboarding.plan_checkout')}
                            </span>
                            <span className="text-stone font-mono text-[10.5px] font-semibold tracking-[0.8px]">STRIPE</span>
                        </div>
                        {summary}
                        {!phone && <Pay variant="desk" plan={plan} tier={tier} email={email} label={payLabel} busy={busy} setBusy={setBusy} />}
                        <Brands />
                        <p className="text-stone text-center text-xs">{payLine}</p>
                    </div>
                </div>
            </OnboardingLayout>
        </Elements>
    );
}

// Paying: Apple Pay / Google Pay (Express Checkout Element) and card (Payment Element). Both go through pay():
// collect + validate the details, have the server create the incomplete subscription (subscribe), confirm its first
// invoice here, then sync and finish (paid). A card that needs 3-D Secure redirects to Stripe and comes back to paid.
function Pay({
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
                buttonHeight: 48,
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
                    title={t('onboarding.plan_card')}
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

// The cards and wallets that work here, as small badges under the pay button (design 06 / D09).
function Brands() {
    return (
        <ul className="text-ink-2 flex flex-wrap justify-center gap-1.5 text-[10px] font-bold" aria-hidden>
            {['VISA', 'MC', 'AMEX', 'Apple Pay', 'G Pay'].map((b) => (
                <li key={b} className="border-line rounded-md border bg-white px-2 py-1 tracking-wide">
                    {b}
                </li>
            ))}
        </ul>
    );
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}
