import { Head, router } from '@inertiajs/react';
import { Elements } from '@stripe/react-stripe-js';
import { Check, Github, Lock, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Homer, reactHomer } from '@/components/homer';
import { LEGAL_LINKS } from '@/components/legal-note';
import {
    Brands,
    Pay,
    PromoSheet,
    StripeMark,
    elementsOptions,
    stripeFor,
    useCheckout,
    type Amounts,
    type Plan,
    type Promo,
    type Tier,
} from '@/components/plan-checkout';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    family: { name: string };
    invited: string[];
    // Billing::pricing(): today's whole-dollar prices (intro while spots last) per tier; `pro` only when Pro is on offer.
    pricing: { intro: boolean; spotsLeft: number; spotsTotal: number; monthly: number; yearly: number; pro?: Amounts };
    // The list prices per tier, for the strike-through and the "Discount" line while the intro offer lasts.
    standard: { basic: Amounts; pro: Amounts } | null;
    // What each tier says it includes ("1 GB · 50 AI points"); display copy, see config familywell.tiers.
    tiers: Record<Tier, { storage_gb: number; ai_points: number }> | null;
    // The publishable key (STRIPE_KEY); the secret stays on the server.
    stripeKey: string | null;
    // VAT / sales tax where the visitor is (Billing::taxRate, from their IP like Stripe Checkout), added on top of
    // the price; null = nothing to add.
    tax: { country: string; percent: number } | null;
    // The promo code applied in this session (Billing::promoCode): percent off or dollars off, and for how long.
    promo: Promo | null;
    email: string;
    failed: boolean;
    paid: boolean;
    // Where the celebration goes next (the "home is ready" recap, or the app for a family paying again).
    next: string | null;
    selfHostingUrl: string | null;
    // Free-trial days left (Billing::trialDaysLeft); 0 = trial over, so paying is the only way in. Absent on "paid".
    trialDaysLeft?: number;
    // First names the one price covers, payer first (OnboardingController::plan); absent on "paid".
    members?: string[];
};

// The tablet / desktop list under the plans; `{storage}` and `{ai}` rows take the tier's numbers.
const INCLUDED = ['plan_inc_modules', 'plan_inc_members', 'storage', 'ai', 'plan_inc_open', 'plan_inc_ads'] as const;
// The phone's shorter list (design 06). "Every module, unlimited members" moved into the whole-family card above it.
const INCLUDED_PHONE = ['storage', 'ai', 'plan_inc_cancel'] as const;
// Avatar fills for the whole-family card, in the design's order (ink, blue, green).
const AVATAR = ['bg-ink text-white', 'bg-[#7AA7FF] text-ink', 'bg-[#6FD08C] text-ink'] as const;

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

// Plan & pay (design 06 / T09 / D09): right after the notifications, before the "home is ready" recap and the tour.
// While the 5-day free trial lasts it can be skipped ("Start my free days", no card); after it, it's the paywall. A Basic / Pro switch (Pro only where it's on offer), yearly preselected,
// the total in plain sight, paid right here with Stripe Elements (Apple Pay / Google Pay first, card second).
// Prices come from Billing::pricing() (config/familywell.php), never from here.
export default function PlanStep({
    family,
    invited,
    pricing,
    standard,
    tiers,
    stripeKey,
    tax,
    promo: appliedPromo,
    email,
    failed,
    paid,
    next,
    selfHostingUrl,
    trialDaysLeft = 0,
    members = [],
}: Props) {
    const t = useT();
    const [plan, setPlan] = useState<Plan>('yearly');
    const [tier, setTier] = useState<Tier>('basic');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (paid) {
            reactHomer('celebrate');
            const id = window.setTimeout(() => router.visit(next ?? route('dashboard')), 1800);
            return () => window.clearTimeout(id);
        }
        if (failed) reactHomer('error');
    }, [paid, failed, next]);

    const {
        usd,
        prices,
        listPrices,
        save,
        setPromo,
        promoOpen,
        setPromoOpen,
        taxAmount,
        total,
        renewAmount,
        list,
        due,
        perMonth,
        renewsText,
        tierName,
        summary,
        payLabel,
        promoControl,
    } = useCheckout({ plan, tier, pricing, standard, tax, appliedPromo });
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
    return (
        // Deferred intent: Elements knows the amount up front and the subscription is only created on Pay (subscribe).
        <Elements stripe={stripePromise} options={elementsOptions(total)}>
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
                                        <span className="text-stone hidden font-mono text-[10.5px] font-medium md:inline">{tierSub(x)}</span>
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
                                        {struck > price && (
                                            <span className="text-stone text-[13px] line-through decoration-[#FF5A3C] decoration-2">
                                                {usd(struck)}
                                            </span>
                                        )}
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

                        {/* Design 06 (YqKQt): say plainly the price is per family, not per person, with who it covers. */}
                        <div className="border-line flex items-center gap-3 rounded-2xl border bg-white px-3.5 py-3">
                            <span className="flex shrink-0 -space-x-2">
                                {members.slice(0, 3).map((m, i) => (
                                    <span
                                        key={i}
                                        className={cn(
                                            'flex size-8 items-center justify-center rounded-full text-sm font-bold ring-2 ring-white',
                                            AVATAR[i],
                                        )}
                                    >
                                        {m.charAt(0).toUpperCase()}
                                    </span>
                                ))}
                                <span className="bg-paper-2 text-ink flex size-8 items-center justify-center rounded-full ring-2 ring-white">
                                    <Plus className="size-4" />
                                </span>
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[15px] font-bold">{t('onboarding.plan_family_title')}</span>
                                <span className="text-stone block text-[13px]">
                                    {members.length > 1
                                        ? t('onboarding.plan_family_sub', { names: members.join(', ') })
                                        : t('onboarding.plan_family_sub_solo')}
                                </span>
                            </span>
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
                                <span className="block text-sm font-semibold">{t('onboarding.plan_due_family')}</span>
                                <span className="text-stone block text-xs">
                                    {t('onboarding.plan_renews_short', { date: renewsText, amount: renewAmount })}
                                </span>
                                {tax && (
                                    <span className="text-stone block text-xs">
                                        {t('onboarding.plan_tax_incl', { amount: usd(taxAmount, true), rate: tax.percent })}
                                    </span>
                                )}
                                <span className="mt-1.5 flex">{promoControl}</span>
                            </span>
                            <span className="text-right">
                                {list > due && (
                                    <span className="text-stone block text-xs line-through decoration-[#FF5A3C] decoration-2">
                                        {usd(tax ? Math.round(list * (100 + tax.percent)) / 100 : list, true)}
                                    </span>
                                )}
                                <span className="font-display block text-2xl font-bold">{usd(total, true)}</span>
                            </span>
                        </div>
                        {phone && <Pay variant="phone" plan={plan} tier={tier} email={email} label={payLabel} busy={busy} setBusy={setBusy} />}
                        {/* Design 06: the legal links under the pay buttons; who pays is now the whole-family card's job. */}
                        <p className="text-ink-2 flex justify-center gap-2 text-xs">
                            <a href={LEGAL_LINKS.terms} target="_blank" rel="noopener">
                                {t('auth.legal_terms')}
                            </a>
                            <span className="text-stone">·</span>
                            <a href={LEGAL_LINKS.privacy} target="_blank" rel="noopener">
                                {t('auth.legal_privacy')}
                            </a>
                        </p>
                        <p className="text-stone flex items-center justify-center gap-1.5 text-xs">
                            <Lock className="size-3.5" />
                            {t('onboarding.plan_secure_by')}
                            <StripeMark />
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
                {trialDaysLeft > 0 && (
                    <div className="flex flex-col items-center gap-1.5 px-5 pb-8 text-center md:px-0">
                        <button
                            type="button"
                            onClick={() => router.post(route('onboarding.plan.trial'))}
                            className="border-line text-ink min-h-12 rounded-full border bg-white px-6 text-base font-bold"
                        >
                            {t('onboarding.plan_trial_cta', { count: trialDaysLeft })}
                        </button>
                        <p className="text-stone text-xs">{t('onboarding.plan_trial_note')}</p>
                    </div>
                )}
            </OnboardingLayout>
            <PromoSheet
                open={promoOpen}
                onOpenChange={setPromoOpen}
                onApplied={(p) => {
                    setPromo(p);
                    setPromoOpen(false);
                    reactHomer('success');
                }}
            />
        </Elements>
    );
}
