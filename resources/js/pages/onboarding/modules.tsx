import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Cake,
    CalendarDays,
    Car,
    Check,
    ChefHat,
    Contact,
    FolderKanban,
    Lightbulb,
    ListChecks,
    LoaderCircle,
    NotebookPen,
    Package,
    ShoppingCart,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const MAX = 4;

// Icons from the design's tiles. Popular = the badge on the two most-used modules.
const META: Record<string, { icon: LucideIcon; popular?: boolean; desktopOnly?: boolean }> = {
    shopping: { icon: ShoppingCart, popular: true },
    tasks: { icon: ListChecks, popular: true },
    calendar: { icon: CalendarDays },
    spending: { icon: Wallet },
    notes: { icon: NotebookPen },
    recipes: { icon: ChefHat },
    inventory: { icon: Package },
    vehicles: { icon: Car },
    projects: { icon: FolderKanban },
    // Phones list ten; Contacts is behind the "+ Contacts and more" line there (design 03 vs D1).
    contacts: { icon: Contact, desktopOnly: true },
    birthdays: { icon: Cake },
};

type Props = { available: string[]; picked: string[]; requestsUrl: string | null };

// Step 3 (design 03 / D1): choosing makes it theirs and keeps the first week simple; the counter turns it into a
// tiny game. Up to four; fewer is fine ("One more? Or go with 3.").
export default function Modules({ available, picked: initial, requestsUrl }: Props) {
    const t = useT();
    const [picked, setPicked] = useState<string[]>(initial);
    const [processing, setProcessing] = useState(false);
    const full = picked.length >= MAX;

    // A fifth tap does nothing (the counter already reads "4 of 4"); untick one to swap.
    const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : p.length >= MAX ? p : [...p, id]));

    const submit = () =>
        router.post(
            route('onboarding.modules.store'),
            { modules: picked },
            { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );

    const counter = (
        <div className="border-line flex w-fit items-center gap-2.5 rounded-full md:shrink-0 md:border md:bg-white md:px-3.5 md:py-2">
            <span className="flex gap-1" aria-hidden>
                {Array.from({ length: MAX }, (_, i) => (
                    <span key={i} className={cn('h-2 w-5 rounded-full', i < picked.length ? 'bg-ink' : 'bg-line')} />
                ))}
            </span>
            <span className="text-[13px] font-semibold" aria-live="polite">
                {full
                    ? t('onboarding.modules_count_full', { n: picked.length, max: MAX })
                    : t('onboarding.modules_count', { n: picked.length, max: MAX })}
            </span>
        </div>
    );

    return (
        <>
            <Head title={t('onboarding.modules_title')} />
            <OnboardingLayout
                step={3}
                homer={{ face: 'thinking' }}
                wide
                back={route('onboarding.invite')}
                title={t('onboarding.modules_title')}
                sub={t('onboarding.modules_sub')}
                aside={counter}
                cta={
                    <Button
                        variant="sun"
                        size="cta"
                        className="w-full md:w-auto md:min-w-[260px]"
                        disabled={processing || picked.length === 0}
                        onClick={submit}
                    >
                        {full ? t('onboarding.modules_cta_full', { n: picked.length }) : t('onboarding.modules_cta', { n: picked.length })}
                        {processing ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
                    </Button>
                }
                hint={
                    full ? (
                        <span className="md:hidden">{t('onboarding.modules_more')}</span>
                    ) : picked.length ? (
                        t('onboarding.modules_one_more', { n: picked.length })
                    ) : (
                        t('onboarding.modules_pick_one')
                    )
                }
            >
                <div className="grid grid-cols-2 gap-2 px-5 md:grid-cols-4 md:gap-3 md:px-0">
                    {available.map((id) => {
                        const meta = META[id];
                        if (!meta) return null;
                        const on = picked.includes(id);
                        const Icon = meta.icon;
                        return (
                            <button
                                key={id}
                                type="button"
                                aria-pressed={on}
                                onClick={() => toggle(id)}
                                className={cn(
                                    'flex flex-col gap-2 rounded-2xl border px-3 py-[11px] text-left transition-colors md:gap-3 md:rounded-[18px] md:p-4',
                                    on ? 'border-ink bg-sun-soft border-[1.5px]' : 'border-line bg-white',
                                    meta.desktopOnly && 'hidden md:flex',
                                )}
                            >
                                <span className="flex w-full items-start justify-between">
                                    <span
                                        className={cn(
                                            'flex size-[30px] items-center justify-center rounded-[9px] md:size-9 md:rounded-[10px]',
                                            on ? 'bg-sun' : 'bg-paper-2',
                                        )}
                                    >
                                        <Icon className="size-4 md:size-[18px]" />
                                    </span>
                                    <span
                                        className={cn(
                                            'flex size-5 items-center justify-center rounded-full md:size-[22px]',
                                            on ? 'bg-ink text-sun' : 'border-line border-[1.5px]',
                                        )}
                                    >
                                        {on && <Check className="size-[13px] md:size-3.5" strokeWidth={3} />}
                                    </span>
                                </span>
                                <span className="flex min-w-0 flex-col gap-px md:gap-[3px]">
                                    <span className="flex items-center gap-1.5">
                                        <span className="text-sm font-bold md:text-[15px]">{t(`onboarding.module_${id}`)}</span>
                                        {meta.popular && (
                                            <span className="bg-ink text-sun rounded-full px-1.5 py-px font-mono text-[8.5px] font-bold tracking-[0.5px]">
                                                {t('onboarding.popular')}
                                            </span>
                                        )}
                                    </span>
                                    {/* Short on phones, the design's longer line where there's room. */}
                                    <span className="text-stone text-[11.5px] leading-snug md:hidden">{t(`onboarding.module_${id}_desc`)}</span>
                                    <span className="text-stone hidden text-[12.5px] leading-snug md:block">
                                        {t(`onboarding.module_${id}_desc_long`)}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                    {requestsUrl && (
                        <a
                            href={requestsUrl}
                            target="_blank"
                            rel="noopener"
                            className="border-line hidden flex-col gap-3 rounded-[18px] border p-4 md:flex"
                        >
                            <span className="flex w-full items-start justify-between">
                                <span className="bg-sun flex size-9 items-center justify-center rounded-[10px]">
                                    <Lightbulb className="size-[18px]" />
                                </span>
                                <ArrowUpRight className="text-stone size-4" />
                            </span>
                            <span className="flex flex-col gap-[3px]">
                                <span className="text-[15px] font-bold">{t('onboarding.modules_request')}</span>
                                <span className="text-stone text-[12.5px] leading-snug">{t('onboarding.modules_request_desc')}</span>
                            </span>
                        </a>
                    )}
                </div>
            </OnboardingLayout>
        </>
    );
}
