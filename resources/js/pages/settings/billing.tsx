import { Head, Link, router } from '@inertiajs/react';
import { Check, Crown, ExternalLink, LogOut } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import AppLogo from '@/components/app-logo';
import { useCheckout, type Amounts, type CheckoutPricing, type Plan, type Promo, type Tax, type Tier } from '@/components/plan-checkout';
import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    family: { name: string };
    subscribed: boolean;
    lifetime: boolean; // comped by a platform admin ("Lifetime (VIP)"): no checkout, no portal
    status: string | null;
    hasCustomer: boolean;
    canManage: boolean;
    plan: string | null; // 'monthly' | 'yearly' | 'unknown', null until synced from Stripe
    periodEnd: string | null; // ISO; when the current period renews (or ends, when canceling)
    canceling: boolean; // canceled at period end: access runs until periodEnd, then stops
    trialDaysLeft?: number; // free-trial days left, 0 = over (Billing::trialDaysLeft)
    locked: boolean; // free period over: the app redirects here. False = came here on their own, can go back
    pricing: CheckoutPricing & { spotsLeft: number };
    // The onboarding checkout's inputs (unpaid families only, see BillingController::show).
    standard?: { basic: Amounts; pro: Amounts } | null;
    tiers?: Record<Tier, { storage_gb: number; ai_points: number }> | null;
    tax?: Tax;
    promo?: Promo | null;
};

// Settings > Billing (design Y6). Unpaid: "One plan, whole family." with the two plan cards, a short included list
// and one big CTA that opens the onboarding's own checkout (components/plan-checkout: Apple Pay / Google Pay, card,
// promo codes, tax) right under it, so there is one way to pay in the app. Paid / lifetime: the plan as settings rows
// with the Stripe portal. Only admins get buttons. A locked family (free period over) has no app chrome: every app
// link would bounce back here, so the header offers sign out instead of the back pill.
export default function Billing(props: Props) {
    const t = useT();
    const { family, subscribed, locked } = props;
    const back = { href: '/settings/family', label: family.name };

    if (subscribed) {
        return (
            <AppLayout hideMobileHeader breadcrumbs={[{ title: t('billing.title'), href: '/settings/billing' }]}>
                <Head title={t('billing.title')} />
                <SettingsPage back={back} title={t('billing.title')} lede={props.lifetime ? t('billing.lifetime_line') : t('billing.thanks')}>
                    <PlanStatus {...props} />
                </SettingsPage>
            </AppLayout>
        );
    }

    const title = t('billing.choose_title');
    // In the free trial (not locked yet): how many days are left, after the one-plan line.
    const lede = [
        t('billing.locked_desc', { family: family.name }),
        !locked && props.trialDaysLeft ? t('billing.trial_left', { count: props.trialDaysLeft }) : null,
    ]
        .filter(Boolean)
        .join(' ');

    if (locked) {
        return (
            <div className="bg-background min-h-svh">
                <Head title={title} />
                <header className="flex items-center justify-between px-4 py-4 sm:px-8">
                    <div className="flex items-center">
                        <AppLogo />
                    </div>
                    <Link
                        method="post"
                        href={route('logout')}
                        as="button"
                        className="text-muted-foreground hover:text-primary flex min-h-11 items-center gap-2 text-sm"
                    >
                        <LogOut className="size-4" />
                        {t('billing.sign_out')}
                    </Link>
                </header>
                {/* SettingsPage's column and title, without its back pill (there is nowhere to go back to). */}
                <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 pt-2 pb-10">
                    <div className="flex flex-col gap-2">
                        <h1 className="font-display text-primary text-[34px] leading-[1.02] font-bold tracking-[-1.2px] text-balance md:text-[40px]">
                            {title}
                        </h1>
                        <p className="text-muted-foreground text-[15px] leading-normal">{lede}</p>
                    </div>
                    <Plans {...props} />
                </div>
            </div>
        );
    }

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('billing.title'), href: '/settings/billing' }]}>
            <Head title={title} />
            <SettingsPage back={back} title={title} lede={lede}>
                <Plans {...props} />
            </SettingsPage>
        </AppLayout>
    );
}

// The paying family's plan, as settings rows: plan + status badge + renewal line, then "Manage plan" (the Stripe
// portal: switch plan, card, invoices, cancel). Lifetime has no portal (nothing to manage); a member sees the plan
// and an "admin only" line instead of the button. The portal needs a Stripe customer.
function PlanStatus({ plan, periodEnd, canceling, lifetime, status, hasCustomer, canManage }: Props) {
    const t = useT();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const date = periodEnd ? new Date(periodEnd).toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' }) : null;
    const pastDue = status === 'past_due';
    const trialing = status === 'trialing';
    // 'unknown' = a price we don't configure (or not synced yet): fall back to the product name.
    const name = !lifetime && (plan === 'monthly' || plan === 'yearly') ? t(`billing.plan_${plan}`) : t('billing.plan_name');
    const badge = lifetime
        ? t('billing.badge_lifetime')
        : pastDue
          ? t('billing.badge_past_due')
          : trialing
            ? t('billing.badge_trial')
            : t('billing.badge_active');
    const sub = lifetime
        ? null
        : pastDue
          ? t('billing.past_due')
          : date && t(canceling ? 'billing.ends_on' : trialing ? 'billing.trial_ends_on' : 'billing.renews_on', { date });
    const canPortal = canManage && hasCustomer && !lifetime;

    return (
        <>
            <SettingsGroup label={t('billing.plan_title')}>
                <SettingsRow
                    icon={Crown}
                    tile
                    title={name}
                    sub={sub && <span className={cn(pastDue && 'text-destructive')}>{sub}</span>}
                    right={
                        <Badge
                            className={cn(
                                'shrink-0 rounded-md px-1.5 py-0 text-[10px] tracking-wide uppercase',
                                pastDue ? 'bg-destructive text-destructive-foreground' : 'bg-primary text-primary-foreground',
                            )}
                        >
                            {badge}
                        </Badge>
                    }
                />
                {canPortal && (
                    <SettingsRow
                        icon={ExternalLink}
                        title={t('billing.manage')}
                        onClick={() => {
                            if (busy) return;
                            setBusy(true);
                            // Inertia::location answer: a full-page redirect to Stripe's hosted portal.
                            router.post(route('billing.portal'), {}, { onFinish: () => setBusy(false) });
                        }}
                    />
                )}
            </SettingsGroup>
            {!canManage && !lifetime && <p className="text-muted-foreground px-1 text-sm">{t('billing.admin_only')}</p>}
        </>
    );
}

// The plan picker (design Y6): Monthly | Yearly cards (yearly preselected, says why: months free), a Basic | Pro
// switch only where Pro is on offer, and the short included list. Read-only: only a member who can't pay sees it.
function Plans({ pricing, standard, tiers, tax, promo: appliedPromo }: Props) {
    const t = useT();
    const [plan, setPlan] = useState<Plan>('yearly');
    const [tier, setTier] = useState<Tier>('basic');
    const { usd, prices, listPrices } = useCheckout({
        plan,
        tier,
        pricing,
        standard: standard ?? null,
        tax: tax ?? null,
        appliedPromo: appliedPromo ?? null,
    });
    // "3 months free": what yearly saves, in months of the monthly price.
    const monthsFree = Math.round(12 - prices.yearly / prices.monthly);

    return (
        <>
            {pricing.pro && tiers && (
                <div className="bg-surface grid grid-cols-2 rounded-full p-1" role="tablist" aria-label={t('onboarding.plan_tiers')}>
                    {(['basic', 'pro'] as const).map((x) => (
                        <button
                            key={x}
                            type="button"
                            role="tab"
                            aria-selected={tier === x}
                            onClick={() => setTier(x)}
                            className={cn(
                                'text-primary min-h-10 rounded-full px-2 py-2 text-sm',
                                tier === x ? 'bg-surface-card font-bold shadow-sm' : 'text-muted-foreground font-medium',
                            )}
                        >
                            {t(`onboarding.tier_${x}`)}
                            <span className="text-muted-foreground ml-1.5 font-mono text-[11px] font-medium">
                                {t('onboarding.plan_tier_sub', { gb: tiers[x].storage_gb, ai: tiers[x].ai_points })}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-2 gap-3" role="radiogroup" aria-label={t('onboarding.plan_choose')}>
                {(['monthly', 'yearly'] as const).map((p) => (
                    <PlanCard
                        key={p}
                        on={plan === p}
                        onPick={() => setPlan(p)}
                        label={t(`billing.plan_${p}`)}
                        price={usd(prices[p])}
                        struck={listPrices[p] > prices[p] ? usd(listPrices[p]) : null}
                        unit={t(p === 'yearly' ? 'billing.per_year' : 'billing.monthly_unit')}
                        hint={p === 'yearly' && monthsFree > 0 ? t('billing.months_free', { count: monthsFree }) : null}
                    />
                ))}
            </div>

            <ul className="flex flex-col gap-3 px-1">
                {['inc_modules', 'inc_members', 'inc_backups', 'inc_ai', 'inc_funds'].map((k) => (
                    <li key={k} className="text-primary flex items-center gap-3 text-[15px]">
                        <span className="bg-accent text-primary flex size-6 shrink-0 items-center justify-center rounded-full">
                            <Check className="size-3.5" strokeWidth={3} />
                        </span>
                        {t(`billing.${k}`)}
                    </li>
                ))}
            </ul>

            {/* Only a member who can't pay sees these plans: an unsubscribed admin is sent to the plan & pay page
                (BillingController::show), the one checkout in the app. */}
            <p className="text-muted-foreground px-1 text-sm">{t('billing.ask_admin')}</p>
        </>
    );
}

function PlanCard({
    on,
    onPick,
    label,
    price,
    struck,
    unit,
    hint,
}: {
    on: boolean;
    onPick: () => void;
    label: string;
    price: string;
    struck: string | null;
    unit: string;
    hint: ReactNode;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={on}
            onClick={onPick}
            className={cn(
                'flex min-w-0 flex-col gap-1 rounded-[22px] border p-4 text-left transition-colors',
                on ? 'border-primary bg-accent-strong text-accent-strong-foreground border-2' : 'bg-surface-card text-primary m-px',
            )}
        >
            <span className="flex items-center justify-between gap-2">
                <span className="truncate font-mono text-xs font-semibold tracking-[0.12em] uppercase">{label}</span>
                <span
                    className={cn(
                        'flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                        on ? 'border-current bg-current' : 'border-current opacity-80',
                    )}
                >
                    {on && <span className="bg-accent-strong size-2 rounded-full" />}
                </span>
            </span>
            <span className="mt-2 flex flex-wrap items-baseline gap-x-2">
                <span className="font-display text-[34px] leading-none font-bold tracking-[-1px]">{price}</span>
                {struck && <span className="text-sm line-through opacity-60">{struck}</span>}
            </span>
            <span className="mt-1 text-sm opacity-80">{unit}</span>
            {hint && <span className="text-sm font-bold">{hint}</span>}
        </button>
    );
}
