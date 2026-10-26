import { Link } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronLeft, Sofa } from 'lucide-react';
import { type ReactNode } from 'react';

import { AppWordmark } from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The four steps of the onboarding, in order (design-app.pen "Onboarding · Flow"). `label` is the short mobile
// header word ("STEP 2 OF 4 · PEOPLE"), `rail` the desktop rail line.
const STEPS = [
    { label: 'onboarding.step_family', rail: 'onboarding.rail_family' },
    { label: 'onboarding.step_people', rail: 'onboarding.rail_people' },
    { label: 'onboarding.step_modules', rail: 'onboarding.rail_modules' },
    { label: 'onboarding.step_install', rail: 'onboarding.rail_install' },
] as const;

type Props = {
    /** 1-4; omitted on the Welcome / recap screens, which have no progress header. */
    step?: number;
    /** Back target (mobile round button + desktop bottom-bar link). */
    back?: string;
    title: ReactNode;
    sub?: ReactNode;
    /** Primary action: full width at the bottom on phones, right of the desktop bottom bar. */
    cta?: ReactNode;
    /** Small line under the CTA on phones, left of it on desktop. */
    hint?: ReactNode;
    /** Extra mobile-only content above the title (e.g. a status pill). */
    children: ReactNode;
    /** Wider main column on desktop (the module grid); the default keeps forms at a readable width. */
    wide?: boolean;
    /** Beside the title on desktop, under it on phones (the module counter). */
    aside?: ReactNode;
};

export function Logo({ dark = false }: { dark?: boolean }) {
    return (
        <span className="flex items-center gap-1.5">
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

// The no-card promise, repeated on the rail the whole way (and as a card on Welcome).
function SettleNote() {
    const t = useT();
    return (
        <div className="border-garage-line flex flex-col gap-1.5 rounded-2xl border p-4">
            <span className="flex items-center gap-2 text-sm font-bold text-white">
                <Sofa className="text-sun size-4" />
                {t('onboarding.no_card_short')}
            </span>
            <p className="text-garage-text text-[12.5px] leading-normal">{t('onboarding.no_card_rail')}</p>
        </div>
    );
}

// Desktop: the dark rail with the four steps, done ones ticked in sun, the current one ringed.
export function Rail({ step }: { step?: number }) {
    const t = useT();
    return (
        <aside className="bg-ink sticky top-0 hidden h-dvh w-[340px] shrink-0 flex-col gap-11 p-9 lg:flex">
            <Logo dark />
            <ol className="flex flex-col gap-1.5">
                {STEPS.map((s, i) => {
                    const n = i + 1;
                    const done = step !== undefined && n < step;
                    const current = n === step;
                    return (
                        <li key={s.rail} className={cn('flex h-12 items-center gap-3 rounded-xl px-3', current && 'bg-white/8')}>
                            <span
                                className={cn(
                                    'flex size-[26px] shrink-0 items-center justify-center rounded-full font-mono text-xs font-semibold',
                                    done && 'bg-sun text-ink',
                                    current && 'border-sun text-sun border-[1.5px]',
                                    !done && !current && 'border-garage-line text-garage-text border-[1.5px]',
                                )}
                            >
                                {done ? <Check className="size-3.5" strokeWidth={3} /> : n}
                            </span>
                            <span
                                className={cn(
                                    'text-[15px]',
                                    current ? 'font-bold text-white' : done ? 'font-medium text-white' : 'text-garage-text font-medium',
                                )}
                            >
                                {t(s.rail)}
                            </span>
                        </li>
                    );
                })}
            </ol>
            <div className="mt-auto">
                <SettleNote />
            </div>
        </aside>
    );
}

// The onboarding frame: phone = paper screen with the back button, "STEP n OF 4 · X" and a 4-segment progress
// bar, CTA pinned to the bottom; desktop = the dark rail + a paper main column with a bottom bar (Back · hint ·
// CTA). Drawn in the fixed brand colours (bg-paper, text-ink, ...), not the skin tokens: this is the brand's
// first impression and the design has one look.
export default function OnboardingLayout({ step, back, title, sub, cta, hint, children, wide = false, aside }: Props) {
    const t = useT();
    const meta = step ? STEPS[step - 1] : undefined;

    return (
        <div className="bg-paper text-ink flex min-h-dvh font-sans">
            <Rail step={step} />

            <div className="flex min-w-0 flex-1 flex-col">
                {/* Phone header: back · step label, then the progress bar. */}
                {meta && (
                    <header className="bg-paper sticky top-0 z-10 flex flex-col gap-3.5 px-5 pt-3.5 pb-2.5 lg:hidden">
                        <div className="flex h-9 items-center justify-between">
                            {back ? (
                                <Link
                                    href={back}
                                    aria-label={t('onboarding.back')}
                                    className="bg-paper-2 flex size-9 items-center justify-center rounded-full"
                                >
                                    <ChevronLeft className="size-[18px]" />
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-ink-2 font-mono text-[11px] font-semibold tracking-[0.6px] uppercase">
                                {t('onboarding.step_of', { n: step!, total: 4 })} · {t(meta.label)}
                            </span>
                        </div>
                        <div className="flex gap-1.5" aria-hidden>
                            {STEPS.map((_, i) => (
                                <span
                                    key={i}
                                    className={cn('h-1 flex-1 rounded-full', i + 1 < step! ? 'bg-ink' : i + 1 === step ? 'bg-sun' : 'bg-line')}
                                />
                            ))}
                        </div>
                    </header>
                )}

                <main className={cn('flex flex-1 flex-col lg:px-16 lg:pt-14 lg:pb-6', !wide && 'lg:max-w-[720px]')}>
                    <div className="flex flex-col gap-3 px-5 pt-[22px] pb-5 lg:flex-row lg:items-end lg:justify-between lg:gap-8 lg:px-0 lg:pt-0 lg:pb-7">
                        <div className="flex flex-col gap-2.5">
                            {step && (
                                <p className="text-stone hidden font-mono text-xs font-semibold tracking-[0.8px] uppercase lg:block">
                                    {t('onboarding.step_of', { n: step, total: 4 })}
                                </p>
                            )}
                            <h1 className="font-display text-[34px] leading-[1.02] font-bold tracking-[-1.2px] text-balance lg:text-[44px] lg:leading-none lg:tracking-[-1.6px]">
                                {title}
                            </h1>
                            {sub && <p className="text-ink-2 text-[15px] leading-normal lg:max-w-[520px] lg:text-base">{sub}</p>}
                        </div>
                        {aside}
                    </div>
                    {children}
                </main>

                {(cta || back) && (
                    <footer className="bg-paper lg:border-line sticky bottom-0 mt-auto flex flex-col items-center gap-3 px-5 pt-3.5 pb-[calc(30px+env(safe-area-inset-bottom))] lg:static lg:flex-row lg:justify-between lg:border-t lg:px-16 lg:py-[18px]">
                        {back ? (
                            <Link href={back} className="text-ink-2 hidden items-center gap-1.5 text-sm font-semibold lg:flex">
                                <ArrowLeft className="size-4" />
                                {t('onboarding.back')}
                            </Link>
                        ) : (
                            <span className="hidden lg:block" />
                        )}
                        <div className="flex w-full flex-col items-center gap-3 lg:w-auto lg:flex-row lg:gap-[18px]">
                            <div className="flex w-full lg:order-2 lg:w-auto">{cta}</div>
                            {hint && <div className="text-stone text-center text-xs lg:order-1 lg:text-[13px]">{hint}</div>}
                        </div>
                    </footer>
                )}
            </div>
        </div>
    );
}
