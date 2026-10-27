import { Head, router } from '@inertiajs/react';
import { Elements, ExpressCheckoutElement, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Appearance, type Stripe } from '@stripe/stripe-js';
import { ArrowRight, Check, CreditCard, Github, LoaderCircle, Lock, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Homer, reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Plan = 'yearly' | 'monthly';
type Props = {
    family: { name: string };
    invited: string[];
    // Billing::pricing(): today's whole-dollar prices (intro while spots last) and the counter.
    pricing: { intro: boolean; spotsLeft: number; spotsTotal: number; monthly: number; yearly: number };
    standard: { monthly: number; yearly: number } | null;
    // The publishable key (STRIPE_KEY); the secret stays on the server.
    stripeKey: string | null;
    email: string;
    canWait: boolean;
    failed: boolean;
    paid: boolean;
    selfHostingUrl: string | null;
};

const INCLUDED = ['plan_inc_modules', 'plan_inc_members', 'plan_inc_backups', 'plan_inc_ai', 'plan_inc_open', 'plan_inc_ads'];

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

// Step 9 (design 09 / T09 / D09): the last screen, after the tour. One plan for the whole family, yearly preselected,
// the total in plain sight, paid right here with Stripe Elements (Apple Pay / Google Pay first, card second) and
// straight into the app. Prices come from Billing::pricing() (config/familywell.php), never from here.
export default function PlanStep({ family, invited, pricing, standard, stripeKey, email, canWait, failed, paid, selfHostingUrl }: Props) {
    const t = useT();
    const locale = useLocale();
    const [plan, setPlan] = useState<Plan>('yearly');
    const [busy, setBusy] = useState(false);
    // Stripe's prices are USD (services.stripe), so the amounts are formatted as dollars in the reader's locale.
    const usd = (n: number, cents = false) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', minimumFractionDigits: cents || n % 1 ? 2 : 0 }).format(n);

    useEffect(() => {
        if (paid) {
            reactHomer('celebrate');
            const id = window.setTimeout(() => router.visit(route('dashboard')), 1800);
            return () => window.clearTimeout(id);
        }
        if (failed) reactHomer('error');
    }, [paid, failed]);

    const save = Math.round((1 - pricing.yearly / (pricing.monthly * 12)) * 100);
    const due = plan === 'yearly' ? pricing.yearly : pricing.monthly;
    const list = standard ? (plan === 'yearly' ? standard.yearly : standard.monthly) : due;
    const renews = new Date();
    if (plan === 'yearly') renews.setFullYear(renews.getFullYear() + 1);
    else renews.setMonth(renews.getMonth() + 1);
    const renewsText = renews.toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });

    const phone = usePhone();
    const stripePromise = useMemo(() => (stripeKey ? stripeFor(stripeKey) : null), [stripeKey]);
    const later = () => router.post(route('onboarding.plan.later'));

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

    const summary = (
        <div className="bg-paper-2 flex flex-col gap-2 rounded-2xl p-4 text-[13px]">
            <div className="flex justify-between">
                <span>{t(`onboarding.plan_line_${plan}`)}</span>
                <span className="font-mono">{usd(list, true)}</span>
            </div>
            {list > due && (
                <div className="flex justify-between">
                    <span>{t('onboarding.plan_intro_line')}</span>
                    <span className="text-ok-ink font-mono">-{usd(list - due, true)}</span>
                </div>
            )}
            <div className="border-line flex items-baseline justify-between border-t pt-2">
                <span className="text-[15px] font-bold">{t('onboarding.plan_due')}</span>
                <span className="text-[17px] font-bold">{usd(due, true)}</span>
            </div>
            <span className="text-stone text-xs">{t('onboarding.plan_renews', { date: renewsText, amount: usd(due) })}</span>
        </div>
    );

    const payLabel = t('onboarding.plan_pay', { amount: usd(due) });

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
                amount: due * 100,
                currency: 'usd',
                appearance: APPEARANCE,
                fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap' }],
            }}
        >
            <Head title={t('onboarding.plan_title', { name: family.name })} />
            <OnboardingLayout
                step={5}
                wide
                homer={{ face: 'happy' }}
                title={t('onboarding.plan_title', { name: family.name })}
                sub={t('onboarding.plan_sub')}
            >
                <div className="grid gap-6 px-5 pb-6 md:grid-cols-2 md:gap-x-5 md:px-0 lg:grid-cols-[minmax(0,430px)_372px] lg:grid-rows-[auto_1fr] lg:gap-x-10">
                    {/* Plans, the intro offer and what's included (left); checkout (right on tablet / desktop). */}
                    <div className="flex flex-col gap-5 md:col-span-2 lg:col-span-1">
                        {failed && <p className="bg-ok-soft text-ink rounded-xl px-3.5 py-2.5 text-[13px]">{t('onboarding.plan_canceled')}</p>}
                        {pricing.intro && (
                            <span className="border-ink bg-sun-soft inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1.5 text-[12.5px] font-semibold">
                                <Sparkles className="size-4" />
                                {t('onboarding.plan_intro', { total: pricing.spotsTotal, left: pricing.spotsLeft })}
                            </span>
                        )}
                        <div className="grid grid-cols-2 gap-2.5" role="radiogroup" aria-label={t('onboarding.plan_choose')}>
                            {(['yearly', 'monthly'] as const).map((p) => {
                                const on = plan === p;
                                const price = p === 'yearly' ? pricing.yearly / 12 : pricing.monthly;
                                return (
                                    <button
                                        key={p}
                                        type="button"
                                        role="radio"
                                        aria-checked={on}
                                        onClick={() => setPlan(p)}
                                        className={cn(
                                            'relative flex flex-col gap-2 rounded-[18px] border p-3.5 text-left md:p-4',
                                            on ? 'border-ink bg-sun-soft border-[1.5px]' : 'border-line bg-white',
                                        )}
                                    >
                                        <span className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'size-5 rounded-full border-2',
                                                    on ? 'border-ink ring-ink bg-white ring-4 ring-inset' : 'border-stone/40',
                                                )}
                                            />
                                            <span className="text-[15px] font-bold">{t(`onboarding.plan_${p}`)}</span>
                                            {p === 'yearly' && save > 0 && (
                                                <span className="bg-ink text-sun absolute -top-2.5 right-3 rounded-full px-2 py-0.5 text-[11px] font-bold md:static md:ml-auto">
                                                    {t('onboarding.plan_save', { n: save })}
                                                </span>
                                            )}
                                        </span>
                                        <span className="flex items-baseline gap-1.5">
                                            <span className="font-display text-[30px] leading-none font-bold tracking-[-1px] md:text-[36px]">
                                                {usd(price)}
                                            </span>
                                            <span className="text-stone text-[13px]">{t('onboarding.plan_per_month')}</span>
                                        </span>
                                        <span className="text-ink-2 text-xs">
                                            {p === 'yearly'
                                                ? t('onboarding.plan_billed_yearly', { amount: usd(pricing.yearly) })
                                                : t('onboarding.plan_billed_monthly')}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex flex-col gap-2.5 lg:row-start-2">
                        <span className="text-stone hidden font-mono text-[11px] font-semibold tracking-[0.8px] uppercase md:block">
                            {t('onboarding.plan_included')}
                        </span>
                        <ul className="flex flex-col gap-2">
                            {INCLUDED.map((k) => (
                                <li key={k} className="flex items-center gap-2.5 text-[13.5px]">
                                    <span className="bg-ok-soft text-ok-ink flex size-[18px] shrink-0 items-center justify-center rounded-full">
                                        <Check className="size-3" strokeWidth={3} />
                                    </span>
                                    {t(`onboarding.${k}`)}
                                </li>
                            ))}
                        </ul>
                        <div className="hidden flex-1 items-end md:flex">
                            <Homer face="happy" className="size-[110px] lg:hidden" />
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
                            <Homer face="happy" className="size-11" />
                            <span className="flex-1">
                                <span className="block text-sm font-semibold">{t('onboarding.plan_due')}</span>
                                <span className="text-stone block text-xs">
                                    {t('onboarding.plan_renews_short', { date: renewsText, amount: usd(due) })}
                                </span>
                            </span>
                            <span className="text-right">
                                <span className="font-display block text-2xl font-bold">{usd(due, true)}</span>
                                {list > due && <span className="text-stone block text-xs line-through">{usd(list)}</span>}
                            </span>
                        </div>
                        {phone && <Pay variant="phone" plan={plan} email={email} label={payLabel} busy={busy} setBusy={setBusy} />}
                        <p className="text-stone text-center text-xs">{t('onboarding.plan_secure', { line: payLine })}</p>
                        {canWait && (
                            <button type="button" onClick={later} className="text-ink-2 text-sm font-semibold">
                                {t('onboarding.plan_later')}
                            </button>
                        )}
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
                        {!phone && <Pay variant="desk" plan={plan} email={email} label={payLabel} busy={busy} setBusy={setBusy} />}
                        <p className="text-stone text-center text-xs">{payLine}</p>
                        {canWait && (
                            <button type="button" onClick={later} className="text-ink-2 text-sm font-semibold">
                                {t('onboarding.plan_later')}
                            </button>
                        )}
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
    email,
    label,
    busy,
    setBusy,
}: {
    variant: 'phone' | 'desk';
    plan: Plan;
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
            body: JSON.stringify({ plan }),
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

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}
