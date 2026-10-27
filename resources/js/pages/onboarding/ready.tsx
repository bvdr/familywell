import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

import { FamilyMark } from '@/components/family-mark';
import { Homer, reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    family: { name: string; color: string; symbol: string | null; emoji: string };
    me: string;
    invited: string[];
    // Every module is on; four are pinned (the onboarding's "Pin your top 4").
    moduleCount: number;
    pinned: string[];
    installedVia: 'standalone' | 'desktop' | 'browser' | null;
    pushOn: number;
    // The paid plan for the last recap row; null where there's no billing (self-hosted, dev).
    plan: { tier: string; cycle: string; renews: string | null } | null;
};

// Avatar colours for the member dots: ink for the person setting it up, then the invite-row colours.
const DOTS = ['#121100', '#7FA8FF', '#5BD38A', '#C8A6FF', '#FF5A3C'];
// The onboarding palette's names, for "The Riveras, on sun yellow".
const COLOR_KEY: Record<string, string> = {
    '#FFDF00': 'sun',
    '#FF5A3C': 'coral',
    '#5BD38A': 'green',
    '#7FA8FF': 'blue',
    '#C8A6FF': 'lilac',
    '#121100': 'ink',
};

// Step 7 (design 07 / T06 / D06): the win, right after paying. A recap of everything they built (the plan
// included, where there is one) so it feels worth keeping, then the short tour or straight in.
export default function Ready({ family, me, invited, moduleCount, pinned, installedVia, pushOn, plan }: Props) {
    const t = useT();
    const locale = useLocale();
    const [busy, setBusy] = useState<'tour' | 'skip' | null>(null);
    useEffect(() => {
        const id = window.setTimeout(() => reactHomer('celebrate'), 400);
        return () => window.clearTimeout(id);
    }, []);

    const go = (tour: boolean) =>
        router.post(route('onboarding.ready.store'), { tour }, { onStart: () => setBusy(tour ? 'tour' : 'skip'), onFinish: () => setBusy(null) });

    const colorKey = COLOR_KEY[family.color.toUpperCase()];
    const people = [me, ...invited].filter(Boolean);
    const recap: [string, string][] = [
        [
            t('onboarding.ready_family'),
            colorKey ? t('onboarding.ready_family_sub', { name: family.name, color: t(`onboarding.color_${colorKey}`) }) : family.name,
        ],
        invited.length
            ? [t('onboarding.ready_invites', { count: invited.length }), t('onboarding.ready_invites_sub')]
            : [t('onboarding.ready_no_invites'), t('onboarding.ready_no_invites_sub')],
        [
            t('onboarding.ready_modules', { count: moduleCount }),
            t('onboarding.ready_pinned', { names: pinned.map((m) => t(`onboarding.module_${m}`)).join(', ') }),
        ],
        [
            installedVia === 'desktop'
                ? t('onboarding.ready_installed_computer')
                : installedVia === 'standalone'
                  ? t('onboarding.ready_installed_home')
                  : t('onboarding.ready_browser'),
            pushOn ? t('onboarding.ready_push', { count: pushOn }) : t('onboarding.ready_push_off'),
        ],
    ];
    if (plan) {
        recap.push([
            t('onboarding.ready_plan_name', { tier: t(`onboarding.tier_${plan.tier}`), cycle: t(`onboarding.ready_cycle_${plan.cycle}`) }),
            plan.renews
                ? t('onboarding.ready_plan_renews', {
                      date: new Date(plan.renews).toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' }),
                  })
                : t('onboarding.ready_plan_anytime'),
        ]);
    }
    const invitedLine = invited.length
        ? t('onboarding.ready_invited', { me, names: listOf(invited, t('onboarding.and')) })
        : t('onboarding.just_you', { name: me });

    const hero = (
        <div className="flex flex-col items-center gap-3 text-center">
            <FamilyMark
                {...family}
                color="#FFFFFF"
                className="border-ink size-[72px] rounded-[20px] border-[1.5px] text-3xl lg:size-[74px] [&_svg]:size-8"
            />
            <span className="flex">
                {people.slice(0, 5).map((p, i) => (
                    <span
                        key={p + i}
                        className="border-sun -ml-1.5 flex size-7 items-center justify-center rounded-full border-2 text-xs font-bold first:ml-0"
                        style={{ backgroundColor: DOTS[i], color: i === 0 ? '#fff' : '#121100' }}
                    >
                        {p[0]?.toUpperCase()}
                    </span>
                ))}
            </span>
            <h1 className="font-display text-[30px] leading-[1.05] font-bold tracking-[-1px] text-balance md:text-[40px] lg:text-[46px] lg:tracking-[-1.6px]">
                {t('onboarding.ready_title', { name: family.name })}
            </h1>
            <p className="text-ink-2 text-[13.5px] md:text-[15px]">{invitedLine}</p>
        </div>
    );

    return (
        <div className="bg-paper text-ink flex min-h-dvh flex-col font-sans lg:flex-row">
            <Head title={t('onboarding.ready_title', { name: family.name })} />

            {/* Sun panel: on top on phone / tablet, the left half on desktop with Homer dancing at the bottom. */}
            <section className="bg-sun flex flex-col items-center px-5 pt-14 pb-8 md:pt-16 lg:w-[520px] lg:shrink-0 lg:justify-between lg:pt-20 lg:pb-14">
                {hero}
                {/* Left panel: mirror view so he faces the content on the right. */}
                <Homer face="happy" view="mirror" className="hidden size-[220px] lg:block" />
            </section>

            <main className="flex flex-1 flex-col lg:px-[72px] lg:pt-20">
                <div className="hidden flex-col gap-2 pb-6 lg:flex">
                    <p className="text-stone font-mono text-xs font-semibold tracking-[0.8px] uppercase">{t('onboarding.ready_eyebrow')}</p>
                    <p className="font-display text-[40px] leading-none font-bold tracking-[-1.4px]">{t('onboarding.ready_heading')}</p>
                </div>

                <ul className="divide-line mx-5 flex flex-col divide-y pt-4 md:mx-14 lg:mx-0 lg:max-w-[616px] lg:pt-0">
                    {recap.map(([title, sub]) => (
                        <li key={title} className="flex items-center gap-3.5 py-3">
                            <span className="bg-ink text-sun flex size-6 shrink-0 items-center justify-center rounded-full">
                                <Check className="size-3.5" strokeWidth={3} />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[15px] font-bold">{title}</span>
                                <span className="text-stone block truncate text-[13px]">{sub}</span>
                            </span>
                        </li>
                    ))}
                </ul>

                <div className="flex justify-center pt-4 lg:hidden">
                    <Homer face="happy" className="size-[100px] md:size-[120px]" />
                </div>

                <div
                    className={cn(
                        'mt-auto flex flex-col items-center gap-3 px-5 pt-4 pb-[calc(30px+env(safe-area-inset-bottom))] md:px-14',
                        'lg:flex-row lg:gap-7 lg:px-0 lg:pb-14',
                    )}
                >
                    <Button variant="sun" size="cta" className="w-full lg:w-auto lg:min-w-[278px]" disabled={busy !== null} onClick={() => go(true)}>
                        {t('onboarding.ready_cta')}
                        {busy === 'tour' ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
                    </Button>
                    <button type="button" disabled={busy !== null} onClick={() => go(false)} className="text-ink-2 text-sm font-semibold">
                        {t('onboarding.ready_skip')}
                    </button>
                </div>
            </main>
        </div>
    );
}

// "Marco and Lucia", "Marco, Lucia and Rose".
function listOf(names: string[], and: string): string {
    return names.length < 2 ? (names[0] ?? '') : `${names.slice(0, -1).join(', ')} ${and} ${names[names.length - 1]}`;
}
