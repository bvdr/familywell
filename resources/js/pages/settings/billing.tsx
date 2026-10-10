import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

type Props = {
    family: { name: string };
    subscribed: boolean;
    status: string | null;
    hasCustomer: boolean;
    canManage: boolean;
};

type Plan = 'monthly' | 'yearly';

// The paywall + subscription page. A locked family is redirected here (RequireFamily). Checkout and the
// customer portal are both Stripe-hosted: the server answers with an Inertia::location to Stripe.
export default function Billing({ family, subscribed, status, hasCustomer, canManage }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('billing.title'), href: '/settings/billing' }];
    const [plan, setPlan] = useState<Plan>('yearly');
    const [busy, setBusy] = useState(false);

    const go = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, { onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('billing.title')} />
            <SettingsLayout>
                {subscribed ? (
                    <div className="space-y-6">
                        <HeadingSmall title={t('billing.active_title')} description={t('billing.active_desc')} />
                        {status === 'past_due' && <p className="text-destructive text-sm">{t('billing.past_due')}</p>}
                        {canManage && hasCustomer ? (
                            <Button disabled={busy} onClick={() => go(route('billing.portal'))}>
                                {t('billing.manage')}
                            </Button>
                        ) : (
                            !canManage && <p className="text-muted-foreground text-sm">{t('billing.admin_only')}</p>
                        )}
                    </div>
                ) : (
                    <div className="space-y-6">
                        <HeadingSmall title={t('billing.locked_title')} description={t('billing.locked_desc', { family: family.name })} />
                        <PlanCard plan={plan} setPlan={setPlan} />
                        {canManage ? (
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => go(route('billing.checkout'), { plan })}
                                className="flex w-full items-center justify-center gap-2 rounded-full bg-[#FFDF00] px-6 py-4 font-bold text-[#121100] disabled:opacity-60"
                            >
                                {t('billing.start')} <ArrowRight className="size-4" />
                            </button>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('billing.ask_admin')}</p>
                        )}
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}

// Mirrors the "Hosted FamilyWell" card on familywell.org (bvdr/familywell-site, #pricing), so the price
// promise reads the same in-app. Brand colours are literal on purpose: this card is not skinned.
// ponytail: prices are copy in lang/billing.json, not fetched from Stripe; keep them in sync with the
// STRIPE_PRICE_* prices (or fetch them if prices start changing often).
function PlanCard({ plan, setPlan }: { plan: Plan; setPlan: (p: Plan) => void }) {
    const t = useT();
    const options: { id: Plan; price: string; unit: string; hint?: string }[] = [
        { id: 'monthly', price: t('billing.monthly_price'), unit: t('billing.monthly_unit') },
        { id: 'yearly', price: t('billing.yearly_price'), unit: t('billing.yearly_unit'), hint: t('billing.yearly_hint') },
    ];
    const includes = ['inc_modules', 'inc_members', 'inc_backups', 'inc_ai', 'inc_funds'];

    return (
        <div className="flex flex-col gap-6 rounded-[28px] bg-[#121100] p-6 text-white sm:p-8">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-xl font-bold">{t('billing.plan_name')}</h3>
                <span className="rounded-full bg-[#FFDF00] px-3 py-1 text-xs font-bold whitespace-nowrap text-[#121100]">{t('billing.badge')}</span>
            </div>
            <div role="radiogroup" className="flex flex-col gap-2">
                {options.map((o) => (
                    <button
                        key={o.id}
                        type="button"
                        role="radio"
                        aria-checked={plan === o.id}
                        onClick={() => setPlan(o.id)}
                        className={cn(
                            'flex items-center gap-3 rounded-2xl bg-[#24231A] px-4 py-3 text-left ring-2 ring-transparent transition',
                            plan === o.id && 'ring-[#FFDF00]',
                        )}
                    >
                        <span className="text-3xl font-bold text-[#FFDF00]">{o.price}</span>
                        <span className="flex-1 text-sm text-[#C9C6B6]">
                            {o.unit}
                            {o.hint && <span className="block text-[#F2F0E6]">{o.hint}</span>}
                        </span>
                        {o.hint && (
                            <em className="rounded-full bg-[#FFDF00] px-2.5 py-1 text-xs font-bold whitespace-nowrap text-[#121100] not-italic">
                                {t('billing.save')}
                            </em>
                        )}
                    </button>
                ))}
            </div>
            <p className="text-[15px] leading-relaxed text-[#E6E3D6]">{t('billing.tagline')}</p>
            <ul className="flex flex-col gap-3 border-t border-[#3A3826] pt-5">
                {includes.map((k) => (
                    <li key={k} className="flex items-center gap-3 text-sm text-[#F2F0E6]">
                        <Check className="size-4 shrink-0 text-[#FFDF00]" />
                        {t(`billing.${k}`)}
                    </li>
                ))}
            </ul>
        </div>
    );
}
