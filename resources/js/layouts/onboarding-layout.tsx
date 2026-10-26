import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronLeft, CreditCard, UsersRound } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';

import { AppWordmark } from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { Homer, reactHomer, type HomerFace } from '@/components/homer';
import type { HomerAction } from '@/lib/homer';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The four setup steps, in order (design-app.pen "Onboarding · Flow"). `label` is the short header word
// ("STEP 2 OF 4 · PEOPLE"), `rail` the desktop rail line. The plan is a fifth stop, after the tour: it shows on
// the rail and as the fifth progress segment, but it isn't "step 5 of 4".
const STEPS = [
    { label: 'onboarding.step_family', rail: 'onboarding.rail_family' },
    { label: 'onboarding.step_people', rail: 'onboarding.rail_people' },
    { label: 'onboarding.step_modules', rail: 'onboarding.rail_modules' },
    { label: 'onboarding.step_install', rail: 'onboarding.rail_install' },
] as const;

type Props = {
    /** 1-4 the setup steps, 5 the plan; omitted on screens without a progress header. */
    step?: number;
    /** Back target (round button in the header; the desktop bottom bar's Back). */
    back?: string;
    title: ReactNode;
    sub?: ReactNode;
    /** Primary action: full width at the bottom on phones, right of the tablet / desktop bottom bar. */
    cta?: ReactNode;
    /** Small line under the CTA on phones, left of it on tablet / desktop. */
    hint?: ReactNode;
    children: ReactNode;
    /** Wider main column on desktop (the module grid, the two-column steps); the default keeps one column readable. */
    wide?: boolean;
    /** Beside the title on tablet / desktop, under it on phones (the module counter). */
    aside?: ReactNode;
    /** Homer on the desktop rail (design: listening on invites, thinking while they choose modules, walking to the phone). */
    homer?: RailHomer;
    /** false = no desktop rail (the screens after setup, D05 on, go full width). */
    rail?: boolean;
    /** Desktop-only sun panel on the right (e.g. the notification examples on D05). */
    panel?: ReactNode;
    /** Overrides the tablet / desktop eyebrow above the title (e.g. "YOU'RE IN · NOTIFICATIONS"). */
    eyebrow?: ReactNode;
    /** Above the eyebrow and title, every size (e.g. the "Opened from your Home Screen" pill). */
    above?: ReactNode;
};

export type RailHomer = { face?: HomerFace; loop?: HomerAction };

export function Logo({ dark = false, className }: { dark?: boolean; className?: string }) {
    return (
        <span className={cn('flex items-center gap-1.5', className)}>
            {dark ? (
                <AppLogoIcon className="size-5 fill-white" />
            ) : (
                <span className="bg-sun flex size-6 items-center justify-center rounded-[7px]">
                    <AppLogoIcon className="fill-ink size-4" />
                </span>
            )}
            <AppWordmark className={cn('h-[15px] w-auto', dark ? 'fill-white' : 'fill-ink')} />
        </span>
    );
}

function Badge({ state, children }: { state: 'done' | 'current' | 'todo'; children: ReactNode }) {
    return (
        <span
            className={cn(
                'flex size-[26px] shrink-0 items-center justify-center rounded-full text-xs font-bold',
                state === 'done' && 'bg-sun text-ink',
                state === 'current' && 'border-sun text-sun border-[1.5px]',
                state === 'todo' && 'border-garage-line text-garage-text border-[1.5px]',
            )}
        >
            {children}
        </span>
    );
}

// Desktop: the dark rail. The four steps (done ones ticked in sun, the current one ringed), the plan after the
// tour, Homer, and the one-price note the whole way.
export function Rail({ step, homer }: { step?: number; homer?: RailHomer }) {
    const t = useT();
    const state = (n: number) => (step === undefined || n > step ? 'todo' : n === step ? 'current' : 'done');
    const row = (n: number, label: string, badge: ReactNode) => (
        <li key={label} className={cn('flex h-12 items-center gap-3 rounded-xl px-3', state(n) === 'current' && 'bg-white/8')}>
            <Badge state={state(n)}>{badge}</Badge>
            <span
                className={cn(
                    'text-[15px]',
                    state(n) === 'current' ? 'font-bold text-white' : state(n) === 'done' ? 'font-medium text-white' : 'text-garage-text font-medium',
                )}
            >
                {t(label)}
            </span>
        </li>
    );

    return (
        <aside className="bg-ink sticky top-0 hidden h-dvh w-[340px] shrink-0 flex-col gap-11 overflow-y-auto p-9 lg:flex">
            <Logo dark />
            <div className="flex flex-col gap-1.5">
                <ol className="flex flex-col gap-1.5">
                    {STEPS.map((s, i) => row(i + 1, s.rail, state(i + 1) === 'done' ? <Check className="size-3.5" strokeWidth={3} /> : i + 1))}
                </ol>
                <p className="text-garage-text flex items-center gap-3 px-3 pt-2.5 pb-1 font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">
                    {t('onboarding.after_tour')}
                    <span className="bg-garage-line h-px flex-1" />
                </p>
                <ol>
                    {row(
                        5,
                        'onboarding.rail_plan',
                        state(5) === 'done' ? <Check className="size-3.5" strokeWidth={3} /> : <CreditCard className="size-3.5" />,
                    )}
                </ol>
            </div>
            <div className="mt-auto flex flex-col items-center gap-6">
                {homer && <Homer {...homer} onDark className="size-[150px]" />}
                <div className="border-garage-line flex w-full flex-col gap-1.5 rounded-2xl border p-4">
                    <span className="flex items-center gap-2 text-sm font-bold text-white">
                        <UsersRound className="text-sun size-4" />
                        {t('onboarding.one_price_short')}
                    </span>
                    <p className="text-garage-text text-[12.5px] leading-normal">{t('onboarding.one_price_rail')}</p>
                </div>
            </div>
        </aside>
    );
}

/** The five progress segments (four steps + the plan). Phone: done ink, current sun; tablet: done and current sun. */
export function Progress({ step }: { step: number }) {
    return (
        <div className="flex gap-1.5" aria-hidden>
            {Array.from({ length: 5 }, (_, i) => (
                <span key={i} className={cn('h-1 flex-1 rounded-full', i + 1 < step ? 'bg-ink md:bg-sun' : i + 1 === step ? 'bg-sun' : 'bg-line')} />
            ))}
        </div>
    );
}

// The onboarding frame, three ways (design: phone 390, tablet 834, desktop 1280):
// - phone: paper screen, back button + "STEP n OF 4 · X" + progress, CTA pinned to the bottom;
// - tablet (md): the same flow with room: logo on the right of the header, a bottom bar with the hint on the left;
// - desktop (lg): the dark rail instead of the header, a bottom bar with Back · hint · CTA.
// Drawn in the fixed brand colours (bg-paper, text-ink, ...), not the skin tokens: this is the brand's first
// impression and the design has one look.
export default function OnboardingLayout({
    step,
    back,
    title,
    sub,
    cta,
    hint,
    children,
    wide = false,
    aside,
    homer,
    rail = true,
    panel,
    eyebrow,
    above,
}: Props) {
    const t = useT();
    // Any validation or server error in the flow: Homer glitches and shakes his head (the text says what's wrong).
    useEffect(() => {
        const offs = [router.on('error', () => reactHomer('error')), router.on('exception', () => reactHomer('error'))];
        return () => offs.forEach((off) => off());
    }, []);
    const meta = step && step <= 4 ? STEPS[step - 1] : undefined;
    const label = step && step <= 4 ? `${t('onboarding.step_of', { n: step, total: 4 })} · ${t(meta!.label)}` : t('onboarding.step_plan');

    return (
        <div className="bg-paper text-ink flex min-h-dvh font-sans">
            {rail && <Rail step={step} homer={homer} />}

            <div className="flex min-w-0 flex-1 flex-col">
                {step && (
                    <header className="bg-paper sticky top-0 z-10 flex flex-col gap-3.5 px-5 pt-3.5 pb-2.5 md:gap-5 md:px-14 md:pt-5 lg:hidden">
                        <div className="relative flex h-9 items-center justify-between md:h-10">
                            {back ? (
                                <Link
                                    href={back}
                                    aria-label={t('onboarding.back')}
                                    className="bg-paper-2 md:border-line flex size-9 items-center justify-center rounded-full md:size-10 md:border md:bg-white"
                                >
                                    <ChevronLeft className="size-[18px]" />
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-ink-2 font-mono text-[11px] font-semibold tracking-[0.6px] uppercase md:absolute md:left-1/2 md:-translate-x-1/2">
                                {label}
                            </span>
                            <Logo className="hidden md:flex" />
                        </div>
                        <Progress step={step} />
                    </header>
                )}

                <main className={cn('flex flex-1 flex-col md:px-14 lg:px-16 lg:pt-14 lg:pb-6', !wide && 'lg:max-w-[720px]')}>
                    <div className="flex flex-col gap-3 px-5 pt-[22px] pb-5 md:flex-row md:items-end md:justify-between md:gap-8 md:px-0 md:pt-10 md:pb-7 lg:pt-0">
                        <div className="flex flex-col gap-2.5">
                            {above}
                            {eyebrow && (
                                <p className="text-stone hidden font-mono text-xs font-semibold tracking-[0.8px] uppercase md:block">{eyebrow}</p>
                            )}
                            {step && !eyebrow && (
                                <p className="text-stone hidden font-mono text-xs font-semibold tracking-[0.8px] uppercase md:block">
                                    <span className="lg:hidden">{step <= 4 ? t('onboarding.step_of', { n: step, total: 4 }) : label}</span>
                                    <span className="hidden lg:inline">{label}</span>
                                </p>
                            )}
                            <h1 className="font-display text-[34px] leading-[1.02] font-bold tracking-[-1.2px] text-balance md:text-[44px] md:leading-none md:tracking-[-1.6px]">
                                {title}
                            </h1>
                            {sub && <p className="text-ink-2 text-[15px] leading-normal md:max-w-[560px] md:text-base">{sub}</p>}
                        </div>
                        {aside}
                    </div>
                    {children}
                </main>

                {cta && (
                    <footer className="bg-paper md:border-line sticky bottom-0 mt-auto flex flex-col items-center gap-3 px-5 pt-3.5 pb-[calc(30px+env(safe-area-inset-bottom))] md:flex-row md:justify-between md:border-t md:px-14 md:py-[18px] lg:static lg:px-16">
                        {back && (
                            <Link href={back} className="text-ink-2 hidden items-center gap-1.5 text-sm font-semibold lg:flex">
                                <ArrowLeft className="size-4" />
                                {t('onboarding.back')}
                            </Link>
                        )}
                        <div className="flex w-full flex-col items-center gap-3 md:flex-row md:justify-between md:gap-[18px] lg:ml-auto lg:w-auto lg:justify-end">
                            <div className="flex w-full md:order-2 md:w-auto">{cta}</div>
                            {hint && (
                                <div className="text-stone md:text-ink-2 lg:text-stone text-center text-xs md:order-1 md:text-left md:text-[13px] md:font-medium lg:font-normal">
                                    {hint}
                                </div>
                            )}
                        </div>
                    </footer>
                )}
            </div>
            {panel && (
                <aside className="bg-sun sticky top-0 hidden h-dvh w-[520px] shrink-0 flex-col items-center justify-center gap-4 px-12 lg:flex">
                    {panel}
                </aside>
            )}
        </div>
    );
}
