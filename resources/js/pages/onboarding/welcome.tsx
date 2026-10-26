import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Sofa, Sparkles, UsersRound } from 'lucide-react';

import { Homer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Logo } from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// Welcome (design 00 / T00 / D00): the promise, the road (four steps, then the plan after the tour) and that it's
// one price for everyone. Phone: the sun peeks in from the corner. Tablet: a sun panel on top with Homer.
// Desktop: the road on the left, Homer on the sun panel on the right.
export default function Welcome({ firstName }: { firstName: string }) {
    const t = useT();
    // The phone keeps four rows (its own words for step 4); tablet and desktop add the plan, greyed.
    const road: [string, string, string?][] = [
        ['onboarding.rail_family', 'onboarding.plan_30s'],
        ['onboarding.rail_people', 'onboarding.plan_later'],
        ['onboarding.rail_modules', 'onboarding.plan_30s'],
        ['onboarding.rail_install', 'onboarding.plan_reminders', 'onboarding.plan_install'],
        ['onboarding.rail_plan', 'onboarding.plan_after_tour'],
    ];
    const title = firstName ? t('onboarding.welcome_name', { name: firstName }) : t('onboarding.welcome_title');

    return (
        <div className="bg-paper text-ink flex min-h-dvh flex-col font-sans lg:flex-row">
            <Head title={t('onboarding.welcome_title')} />

            {/* Tablet / desktop sun panel. */}
            <section className="bg-sun relative hidden flex-col px-14 pt-8 pb-10 md:flex lg:order-2 lg:w-[500px] lg:shrink-0 lg:px-10 lg:pt-10">
                <div className="flex items-center justify-between lg:justify-end">
                    <Logo className="lg:hidden" />
                    <span className="bg-ink inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-[12.5px] font-semibold text-white">
                        <Sparkles className="text-sun size-4" />
                        {t('onboarding.every_module')}
                    </span>
                </div>
                <div className="flex flex-1 items-center justify-center py-6">
                    <Homer face="happy" greet follow idle={30} className="size-[260px] lg:size-[300px]" />
                </div>
                <div className="bg-ink hidden flex-col gap-2 rounded-[20px] p-[22px] lg:flex">
                    <span className="flex items-center gap-2.5 text-base font-bold text-white">
                        <span className="bg-sun flex size-[30px] items-center justify-center rounded-[9px]">
                            <UsersRound className="text-ink size-4" />
                        </span>
                        {t('onboarding.one_price_title')}
                    </span>
                    <p className="text-garage-text text-[13.5px] leading-normal">{t('onboarding.one_price_body')}</p>
                </div>
            </section>

            <main
                className={cn(
                    'flex min-w-0 flex-1 flex-col',
                    // Phone only: the sun peeking in from the corner, as a background (nothing hangs off the edge).
                    'bg-[radial-gradient(circle_at_calc(100%-40px)_40px,var(--color-sun)_100px,transparent_101px)] md:bg-none',
                    'lg:px-[72px] lg:pt-11',
                )}
            >
                <div className="px-5 pt-5 md:hidden lg:block lg:px-0 lg:pt-0">
                    <Logo className="lg:[&_svg:last-child]:h-[19px]" />
                </div>

                <div className="flex flex-col gap-3.5 px-5 pt-9 pb-6 md:px-14 md:pt-11 lg:max-w-[640px] lg:px-0 lg:pt-14">
                    <p className="text-stone hidden font-mono text-xs font-semibold tracking-[0.8px] uppercase md:block">
                        {firstName ? t('onboarding.welcome_eyebrow', { name: firstName }) : t('onboarding.welcome_eyebrow_anon')}
                    </p>
                    <h1 className="font-display text-[46px] leading-[0.98] font-bold tracking-[-1.8px] md:text-[56px] md:tracking-[-2.2px] lg:text-[68px] lg:tracking-[-2.6px]">
                        {title}
                    </h1>
                    <p className="text-ink-2 text-base leading-normal md:hidden">{t('onboarding.welcome_sub')}</p>
                    <p className="text-ink-2 hidden text-[17px] leading-normal md:block lg:text-lg">{t('onboarding.welcome_sub_wide')}</p>
                </div>

                <ol className="mx-5 md:mx-14 lg:mx-0 lg:max-w-[636px]">
                    {road.map(([title, meta, phoneTitle], i) => (
                        <li
                            key={title}
                            className={cn('border-line flex items-center gap-3.5 border-b py-[13px] first:border-t', i === 4 && 'hidden md:flex')}
                        >
                            <span className="text-stone md:text-ink font-mono text-xs font-semibold">{String(i + 1).padStart(2, '0')}</span>
                            <span className={cn('flex-1 text-[15px] font-semibold md:text-base', i === 4 && 'text-stone')}>
                                {phoneTitle ? (
                                    <>
                                        <span className="md:hidden">{t(phoneTitle)}</span>
                                        <span className="hidden md:inline">{t(title)}</span>
                                    </>
                                ) : (
                                    t(title)
                                )}
                            </span>
                            <span className="text-stone text-xs md:font-mono md:text-[11.5px]">{t(meta)}</span>
                        </li>
                    ))}
                </ol>

                {/* Phone: the dark promise card; tablet: its one-price twin (desktop has it on the sun panel). */}
                <div className="bg-ink mx-5 mt-[22px] flex flex-col gap-2 rounded-[20px] p-[18px] md:mx-14 md:mt-7 md:p-[22px] lg:hidden">
                    <span className="flex items-center gap-2.5">
                        <span className="bg-sun flex size-[30px] shrink-0 items-center justify-center rounded-[9px]">
                            <Sofa className="size-[17px] md:hidden" />
                            <UsersRound className="hidden size-4 md:block" />
                        </span>
                        <span className="font-display text-lg font-bold tracking-[-0.3px] text-white md:hidden">{t('onboarding.no_card_title')}</span>
                        <span className="hidden text-base font-bold text-white md:inline">{t('onboarding.one_price_title')}</span>
                    </span>
                    <p className="text-garage-text text-[13.5px] leading-normal md:hidden">{t('onboarding.no_card_body')}</p>
                    <p className="text-garage-text hidden text-[13.5px] leading-normal md:block">{t('onboarding.one_price_body')}</p>
                </div>

                <div className="flex justify-center pt-6 md:hidden">
                    <Homer face="happy" greet follow className="size-[110px]" />
                </div>

                <div className="md:border-line mt-auto flex flex-col items-center gap-3.5 px-5 pt-4 pb-[calc(30px+env(safe-area-inset-bottom))] md:mt-10 md:flex-row-reverse md:justify-between md:border-t md:px-14 md:py-5 lg:flex-row lg:justify-start lg:gap-7 lg:border-0 lg:px-0 lg:pb-11">
                    <Button asChild variant="sun" size="cta" className="w-full md:w-auto md:min-w-[320px] lg:min-w-[262px]">
                        <Link href={route('onboarding.create')}>
                            {t('onboarding.welcome_cta')}
                            <ArrowRight />
                        </Link>
                    </Button>
                    <JoinHint />
                </div>
            </main>
        </div>
    );
}

// Joining happens through the invitation link (there are no codes to type), so this explains where to find it.
function JoinHint() {
    const t = useT();
    return (
        <Dialog>
            <DialogTrigger className="text-stone text-sm">
                {t('onboarding.join_q')} <span className="text-ink font-semibold">{t('onboarding.join_a')}</span>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('onboarding.join_title')}</DialogTitle>
                    <DialogDescription>{t('onboarding.join_body')}</DialogDescription>
                </DialogHeader>
            </DialogContent>
        </Dialog>
    );
}
