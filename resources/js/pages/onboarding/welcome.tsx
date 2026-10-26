import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Sofa } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Logo, Rail } from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';

// Welcome (design 00): sets expectations, four steps and about two minutes, and that there's no card today.
export default function Welcome({ firstName }: { firstName: string }) {
    const t = useT();
    const plan = [
        ['onboarding.rail_family', 'onboarding.plan_30s'],
        ['onboarding.rail_people', 'onboarding.plan_later'],
        ['onboarding.rail_modules', 'onboarding.plan_30s'],
        ['onboarding.plan_install', 'onboarding.plan_reminders'],
    ];

    return (
        <div className="bg-paper text-ink flex min-h-dvh font-sans">
            <Head title={t('onboarding.welcome_title')} />
            <Rail />

            {/* The sun peeking in from the top right corner: a background, not an element hanging off the edge, so
                nothing is clipped (and the viewport check doesn't read it as cut-off content). */}
            <div className="relative flex min-w-0 flex-1 flex-col bg-[radial-gradient(circle_at_calc(100%-40px)_40px,var(--color-sun)_100px,transparent_101px)] lg:bg-[radial-gradient(circle_at_calc(100%-64px)_64px,var(--color-sun)_160px,transparent_161px)]">
                <div className="relative px-5 pt-5 lg:hidden">
                    <Logo />
                </div>

                <main className="relative flex flex-1 flex-col lg:max-w-[640px] lg:px-16 lg:pt-14">
                    <div className="flex flex-col gap-3.5 px-5 pt-9 pb-6 lg:px-0 lg:pt-0">
                        <h1 className="font-display text-[46px] leading-[0.98] font-bold tracking-[-1.8px] lg:text-[56px]">
                            {firstName ? t('onboarding.welcome_name', { name: firstName }) : t('onboarding.welcome_title')}
                        </h1>
                        <p className="text-ink-2 text-base leading-normal">{t('onboarding.welcome_sub')}</p>
                    </div>

                    <ol className="mx-5 lg:mx-0">
                        {plan.map(([title, meta], i) => (
                            <li key={title} className="border-line flex items-center gap-3.5 border-b py-[13px] first:border-t">
                                <span className="text-stone font-mono text-xs font-semibold">{String(i + 1).padStart(2, '0')}</span>
                                <span className="flex-1 text-[15px] font-semibold">{t(title)}</span>
                                <span className="text-stone text-xs">{t(meta)}</span>
                            </li>
                        ))}
                    </ol>

                    <div className="bg-ink mx-5 mt-[22px] flex flex-col gap-2 rounded-[20px] p-[18px] lg:mx-0">
                        <span className="flex items-center gap-2.5">
                            <span className="bg-sun flex size-[30px] shrink-0 items-center justify-center rounded-[9px]">
                                <Sofa className="size-[17px]" />
                            </span>
                            <span className="font-display text-lg font-bold tracking-[-0.3px] text-white">{t('onboarding.no_card_title')}</span>
                        </span>
                        <p className="text-garage-text text-[13.5px] leading-normal">{t('onboarding.no_card_body')}</p>
                    </div>

                    <div className="mt-auto flex flex-col items-center gap-3.5 px-5 pt-10 pb-[calc(30px+env(safe-area-inset-bottom))] lg:items-start lg:px-0">
                        <Button asChild variant="sun" size="cta" className="w-full lg:w-auto lg:min-w-[260px]">
                            <Link href={route('onboarding.create')}>
                                {t('onboarding.welcome_cta')}
                                <ArrowRight />
                            </Link>
                        </Button>
                        <JoinHint />
                    </div>
                </main>
            </div>
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
