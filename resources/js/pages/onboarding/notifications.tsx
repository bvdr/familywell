import { Head, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Cake,
    CalendarDays,
    Car,
    CircleCheck,
    FolderKanban,
    ListChecks,
    LoaderCircle,
    NotebookPen,
    Package,
    ShoppingCart,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Homer, reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { subscribeToPush } from '@/lib/push';
import { cn } from '@/lib/utils';

type Props = { categories: string[]; names: string[]; installedVia: 'standalone' | 'desktop' | 'browser' | null };

const ICON: Record<string, LucideIcon> = {
    shopping: ShoppingCart,
    calendar: CalendarDays,
    tasks: ListChecks,
    spending: Wallet,
    notes: NotebookPen,
    inventory: Package,
    vehicles: Car,
    projects: FolderKanban,
    birthdays: Cake,
};
// The design starts every picked category on except money: expenses are the one people find noisy.
const OFF_BY_DEFAULT = ['spending'];
const TIMES = ['now', '8:02', '9:00'];

// Which device is about to show the permission prompt, for "Your iPhone will ask next".
function deviceWord(): 'iphone' | 'ipad' | 'browser' {
    if (typeof navigator === 'undefined') return 'browser';
    if (/iphone/i.test(navigator.userAgent)) return 'iphone';
    if (/ipad/i.test(navigator.userAgent) || (/macintosh/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1)) return 'ipad';
    return 'browser';
}

// Step 5 (design 05 / T05 / D05): real examples from the modules they picked so "Allow" feels useful, granular
// toggles, then the browser's own permission prompt. Quiet by default, never marketing.
export default function Notifications({ categories, names, installedVia }: Props) {
    const t = useT();
    const vapid = (usePage().props as { vapidPublicKey?: string }).vapidPublicKey ?? '';
    const [on, setOn] = useState<Record<string, boolean>>(() => Object.fromEntries(categories.map((c) => [c, !OFF_BY_DEFAULT.includes(c)])));
    const [busy, setBusy] = useState(false);
    const [device, setDevice] = useState<'iphone' | 'ipad' | 'browser'>('browser');
    useEffect(() => setDevice(deviceWord()), []);
    // The examples popping in: Homer's roof pops off.
    useEffect(() => {
        const id = window.setTimeout(() => reactHomer('surprise'), 600);
        return () => window.clearTimeout(id);
    }, []);

    const done = (granted: boolean) => router.post(route('onboarding.notifications.store'), { granted }, { onFinish: () => setBusy(false) });

    const turnOn = async () => {
        setBusy(true);
        // The toggles first (same endpoint as Settings > Notifications), then the browser's permission prompt.
        await fetch(route('push.prefs'), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ prefs: on }),
        });
        const granted = await subscribeToPush(vapid);
        reactHomer(granted ? 'celebrate' : 'no');
        window.setTimeout(() => done(granted), granted ? 1200 : 600);
    };

    const examples = categories.slice(0, 3).map((c, i) => ({
        c,
        time: i === 0 ? t('onboarding.notify_now') : TIMES[i],
        body: t(`onboarding.notify_example_${c}`, { a: names[i % names.length] ?? names[0] ?? '', b: names[(i + 1) % names.length] ?? '' }),
    }));

    const exampleCards = (big: boolean) =>
        examples.map((e) => (
            <div key={e.c} className={cn('flex w-full gap-3 rounded-2xl bg-white/85 p-3', big && 'md:p-3.5')}>
                <span className="bg-sun flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                    <AppLogoIcon className="fill-ink size-5" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                        <span className="truncate text-[13px] font-bold">FamilyWell · {t(`onboarding.module_${e.c}`)}</span>
                        <span className="text-stone shrink-0 text-[11px]">{e.time}</span>
                    </span>
                    <span className="text-ink-2 block text-[13px] leading-snug">{e.body}</span>
                </span>
            </div>
        ));

    const pill =
        installedVia === 'standalone' || installedVia === 'desktop' ? (
            <span className="bg-ok-soft text-ok-ink inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold">
                <CircleCheck className="size-3.5" />
                {installedVia === 'desktop' ? t('onboarding.notify_installed_computer') : t('onboarding.notify_installed_home')}
            </span>
        ) : null;

    return (
        <>
            <Head title={t('onboarding.notify_title')} />
            <OnboardingLayout
                rail={false}
                above={pill}
                eyebrow={t('onboarding.notify_eyebrow')}
                title={t('onboarding.notify_title')}
                sub={t('onboarding.notify_sub')}
                cta={
                    // Phone / tablet: the "will ask next" line above the button (design 05 / T05); desktop: below the row.
                    <div className="flex w-full flex-col items-center gap-3 lg:flex-row lg:flex-wrap lg:gap-x-6 lg:gap-y-4">
                        <p className="text-stone text-center text-xs lg:order-last lg:w-full lg:text-left lg:text-[13px]">
                            {t(`onboarding.notify_ask_${device}`)}
                        </p>
                        <Button variant="sun" size="cta" className="w-full lg:w-auto lg:min-w-[244px]" disabled={busy} onClick={() => void turnOn()}>
                            {t('onboarding.notify_cta')}
                            {busy ? <LoaderCircle className="animate-spin" /> : <Bell />}
                        </Button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => {
                                reactHomer('no');
                                setBusy(true);
                                done(false);
                            }}
                            className="text-ink-2 text-sm font-semibold"
                        >
                            {t('onboarding.notify_later')}
                        </button>
                    </div>
                }
                panel={
                    <>
                        <Homer face="happy" className="size-[110px]" />
                        <span className="text-ink font-mono text-[11px] font-semibold tracking-[0.8px] uppercase">
                            {t('onboarding.notify_panel')}
                        </span>
                        <div className="flex w-full flex-col gap-2.5 [&>div]:bg-[#FFF8D6]">{exampleCards(false)}</div>
                    </>
                }
            >
                <div className="flex flex-col gap-4 px-5 md:px-0 lg:max-w-[600px]">
                    {/* Phone / tablet: the examples in a sun card (desktop shows them on the panel). */}
                    {examples.length > 0 && (
                        <div className="relative flex flex-col gap-2 rounded-[22px] bg-gradient-to-b from-[#FFDF00] to-[#FFB547] p-3 lg:hidden">
                            <Homer face="happy" className="absolute -top-11 right-3 size-12" />
                            {exampleCards(true)}
                        </div>
                    )}
                    <ul className="divide-line flex flex-col divide-y">
                        {categories.map((c) => {
                            const Icon = ICON[c] ?? Bell;
                            return (
                                <li key={c} className="flex items-center gap-3 py-3">
                                    <Icon className="size-[18px] shrink-0" />
                                    <span className="flex-1 text-[15px]">{t(`onboarding.notify_${c}`)}</span>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={on[c]}
                                        aria-label={t(`onboarding.notify_${c}`)}
                                        onClick={() => {
                                            reactHomer(on[c] ? 'no' : 'success');
                                            setOn({ ...on, [c]: !on[c] });
                                        }}
                                        className={cn(
                                            'relative h-7 w-11 shrink-0 rounded-full transition-colors',
                                            on[c] ? 'bg-ink' : 'bg-paper-2 border-line border',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'absolute top-1/2 size-5 -translate-y-1/2 rounded-full transition-all',
                                                on[c] ? 'bg-sun left-[21px]' : 'left-[3px] bg-white shadow-sm',
                                            )}
                                        />
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </OnboardingLayout>
        </>
    );
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}
