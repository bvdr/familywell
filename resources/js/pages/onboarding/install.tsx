import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    BellRing,
    Camera,
    Check,
    Download,
    EllipsisVertical,
    LoaderCircle,
    Maximize,
    Pointer,
    Share,
    SquarePlus,
    Zap,
} from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState, useSyncExternalStore, type ReactNode } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Homer, reactHomer } from '@/components/homer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import OnboardingLayout, { Rail } from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { canPromptInstall, isStandalone, onInstallPromptChange, promptInstall } from '@/lib/install';
import { cn } from '@/lib/utils';

type Props = { email: string; step: string | null; preview: boolean };
type Via = 'standalone' | 'desktop' | 'browser';

const advance = (via: Via) => router.post(route('onboarding.install.store'), { via });
const useCanPrompt = () => useSyncExternalStore(onInstallPromptChange, canPromptInstall, () => false);
// A computer (app.css `desk:` variant): wide and with a fine pointer. Only a computer gets the QR hand-off; an iPad,
// however wide, installs like a phone. Only the computer asks for a QR sign-in link.
const DESK = '(min-width: 64rem) and (pointer: fine)';
const isDesktop = () => typeof window !== 'undefined' && window.matchMedia(DESK).matches;
// iPadOS Safari reports itself as a Mac; the touch points give it away.
const isIpad = () =>
    typeof navigator !== 'undefined' &&
    (/ipad/i.test(navigator.userAgent) || (/macintosh/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1));

// After this long on the phone screen, offer a quiet way on for browsers that can't install (in-app browsers).
const ESCAPE_AFTER_MS = 30_000;

// Step 4 (design 04 / D2). Phone: add to Home Screen, required; the page moves on by itself as soon as it runs
// installed. Computer: "Now take it to your phone" with a QR code that signs the phone in.
export default function Install({ email, step, preview }: Props) {
    const t = useT();

    // Opened from the Home Screen (or the installed desktop app): that's the step done.
    useEffect(() => {
        // Opened installed: Homer celebrates for a beat, then on to the next step.
        if (!isStandalone()) return;
        reactHomer('celebrate');
        const id = window.setTimeout(() => advance('standalone'), 1500);
        return () => window.clearTimeout(id);
    }, []);

    // On the computer, follow the phone: once it has moved past this step, so do we (the app resumes the
    // onboarding wherever it now is).
    useEffect(() => {
        if (!isDesktop() || preview) return;
        if (step !== 'install') {
            router.visit(route('dashboard'));
            return;
        }
        const id = window.setInterval(() => router.reload({ only: ['step'] }), 4000);
        return () => window.clearInterval(id);
    }, [step, preview]);

    return (
        <>
            <Head title={t('onboarding.install_title')} />
            <div className="desk:hidden">
                <PhoneInstall />
            </div>
            <div className="desk:block hidden">
                <DesktopHandoff email={email} preview={preview} />
            </div>
        </>
    );
}

function PhoneInstall() {
    const t = useT();
    const [device, setDevice] = useState<'ios' | 'android'>(() =>
        typeof navigator !== 'undefined' && /android/i.test(navigator.userAgent) ? 'android' : 'ios',
    );
    const canPrompt = useCanPrompt();
    // iPad: Share lives in Safari's top bar, so the steps say so and the bubble points up (design T04).
    const [ipad, setIpad] = useState(false);
    useEffect(() => setIpad(isIpad()), []);
    const [escape, setEscape] = useState(false);
    useEffect(() => {
        const id = window.setTimeout(() => setEscape(true), ESCAPE_AFTER_MS);
        return () => window.clearTimeout(id);
    }, []);

    const ios: [ReactNode, string, string, boolean?][] = [
        [<Share key="s" className="size-6" />, 'onboarding.ios_1', ipad ? 'onboarding.ios_1_sub_ipad' : 'onboarding.ios_1_sub', true],
        [<SquarePlus key="p" className="size-6" />, 'onboarding.ios_2', 'onboarding.ios_2_sub'],
        [<AppIcon key="a" />, 'onboarding.install_3', 'onboarding.install_3_sub'],
    ];
    const android: [ReactNode, string, string, boolean?][] = [
        [<EllipsisVertical key="m" className="size-6" />, 'onboarding.android_1', 'onboarding.android_1_sub', true],
        [<Download key="d" className="size-6" />, 'onboarding.android_2', 'onboarding.android_2_sub'],
        [<AppIcon key="a" />, 'onboarding.install_3', 'onboarding.install_3_sub'],
    ];

    return (
        <OnboardingLayout
            step={4}
            homer={{ loop: 'walk' }}
            back={route('onboarding.modules')}
            title={t('onboarding.install_title')}
            sub={t('onboarding.install_sub')}
        >
            <div className="flex flex-col px-5 pb-6 md:px-0">
                <div className="bg-paper-2 mb-3.5 grid grid-cols-2 rounded-full p-1 md:max-w-[420px]" role="tablist">
                    {(['ios', 'android'] as const).map((d) => (
                        <button
                            key={d}
                            type="button"
                            role="tab"
                            aria-selected={device === d}
                            onClick={() => setDevice(d)}
                            className={cn('rounded-full py-2 text-[13px]', device === d ? 'bg-white font-bold' : 'text-stone font-medium')}
                        >
                            {t(`onboarding.device_${d === 'ios' && ipad ? 'ipad' : d}`)}
                        </button>
                    ))}
                </div>

                {/* Android with Chrome's one-tap install on offer: one button instead of the menu steps. */}
                {device === 'android' && canPrompt ? (
                    <Button variant="sun" size="cta" className="mb-2 w-full" onClick={() => void promptInstall()}>
                        <Download />
                        {t('onboarding.android_install')}
                    </Button>
                ) : null}

                <ol className="flex flex-col gap-2">
                    {(device === 'ios' ? ios : android).map(([icon, title, sub, first]) => (
                        <li key={title} className="border-line flex items-center gap-3.5 rounded-2xl border bg-white px-3.5 py-3">
                            <span
                                className={cn(
                                    'flex size-[52px] shrink-0 items-center justify-center overflow-hidden rounded-[14px]',
                                    first ? 'bg-sun border-ink border-[1.5px]' : 'bg-paper-2',
                                )}
                            >
                                {icon}
                            </span>
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-[14.5px] font-bold">{t(title)}</span>
                                <span className="text-stone text-[12.5px] leading-[1.4]">{t(sub)}</span>
                            </span>
                        </li>
                    ))}
                </ol>

                <ul className="grid grid-cols-3 gap-2 pt-4">
                    {[
                        [BellRing, 'onboarding.benefit_reminders'],
                        [Zap, 'onboarding.benefit_instant'],
                        [Maximize, 'onboarding.benefit_fullscreen'],
                    ].map(([Icon, label]) => {
                        const I = Icon as typeof Zap;
                        return (
                            <li key={label as string} className="flex flex-col items-center gap-1.5 px-1 py-2.5 text-center">
                                <I className="size-[18px]" />
                                <span className="text-ink-2 text-xs font-semibold">{t(label as string)}</span>
                            </li>
                        );
                    })}
                </ul>

                <div
                    className="border-line mt-1 flex items-center gap-1.5 self-center rounded-full border bg-white py-1 pr-3.5 pl-1.5 md:order-last md:mt-10"
                    aria-live="polite"
                >
                    {/* Homer walking: the design's 30px loader while they go and install. */}
                    <Homer loop="walk" className="-my-1 size-[30px]" />
                    <span className="text-ink-2 text-[12.5px] font-semibold">{t('onboarding.install_waiting')}</span>
                </div>

                {/* Tablet: Homer walking in the empty space while they install. */}
                <Homer loop="walk" className="mt-10 hidden size-[180px] self-center md:block" />

                {escape && (
                    <button
                        type="button"
                        onClick={() => advance('browser')}
                        className="text-stone mt-5 self-center text-xs underline underline-offset-2"
                    >
                        {t('onboarding.install_escape')}
                    </button>
                )}
            </div>

            {/* iPhone: a bubble pointing down at Safari's real Share button, in the toolbar right under the page. */}
            {/* iPad: Share is top right in Safari's own bar, just above the page, so the bubble points up there. */}
            {device === 'ios' && ipad && (
                <div className="pointer-events-none fixed top-2 right-4 z-20 flex flex-col items-end">
                    <svg width="16" height="9" viewBox="0 0 16 9" className="fill-ink mr-6" aria-hidden>
                        <path d="M0 9h16L8 0z" />
                    </svg>
                    <span className="bg-ink flex items-center gap-2 rounded-[14px] px-3.5 py-2.5 text-[13px] font-bold text-white">
                        <Pointer className="text-sun size-4" />
                        {t('onboarding.ios_bubble')}
                    </span>
                </div>
            )}
            {device === 'ios' && !ipad && (
                <div className="pointer-events-none sticky bottom-[calc(12px+env(safe-area-inset-bottom))] mt-auto flex flex-col items-center">
                    <span className="bg-ink flex items-center gap-2 rounded-[14px] px-3.5 py-2.5 text-[13px] font-bold text-white">
                        <Pointer className="text-sun size-4" />
                        {t('onboarding.ios_bubble')}
                    </span>
                    <svg width="16" height="9" viewBox="0 0 16 9" className="fill-ink" aria-hidden>
                        <path d="M0 0h16L8 9z" />
                    </svg>
                </div>
            )}
        </OnboardingLayout>
    );
}

// The FamilyWell Home Screen icon, as it'll look once added.
function AppIcon() {
    return (
        <span className="bg-sun flex size-full items-center justify-center">
            <AppLogoIcon className="fill-ink size-7" />
        </span>
    );
}

function DesktopHandoff({ email, preview }: { email: string; preview: boolean }) {
    const t = useT();
    const [svg, setSvg] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [onComputer, setOnComputer] = useState(false);
    const canPrompt = useCanPrompt();

    // A fresh single-use sign-in link for the QR, renewed a minute before it expires (Handoff::TTL_MINUTES).
    useEffect(() => {
        if (!isDesktop()) return;
        let timer: number | undefined;
        const draw = (url: string) =>
            // H: the logo covers the middle, so use the error correction that survives it.
            QRCode.toString(url, { type: 'svg', margin: 0, errorCorrectionLevel: 'H', color: { dark: '#121100', light: '#ffffff' } }).then(setSvg);
        // ?preview=1: nobody to hand off, so a QR of the site itself (signs nobody in).
        if (preview) {
            void draw(window.location.origin);
            return;
        }
        const load = async () => {
            const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            const res = await fetch(route('onboarding.handoff'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const { url, ttl } = (await res.json()) as { url: string; ttl: number };
            await draw(url);
            timer = window.setTimeout(load, Math.max(30, ttl - 60) * 1000);
        };
        void load();
        return () => window.clearTimeout(timer);
    }, [preview]);

    const sendLink = () =>
        router.post(
            route('onboarding.handoff.email'),
            {},
            { preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false), onSuccess: () => setSent(true) },
        );

    return (
        <div className="bg-paper text-ink flex min-h-dvh">
            <Rail step={4} homer={{ loop: 'walk' }} />
            <main className="flex min-w-0 flex-1 flex-col justify-center gap-[26px] py-14 pr-14 pl-16">
                <div className="flex max-w-[440px] flex-col gap-3">
                    <p className="text-stone font-mono text-xs font-semibold tracking-[0.8px] uppercase">
                        {t('onboarding.step_of', { n: 4, total: 4 })}
                    </p>
                    <h1 className="font-display text-[48px] leading-none font-bold tracking-[-1.8px] text-balance">
                        {t('onboarding.handoff_title')}
                    </h1>
                    <p className="text-ink-2 text-base leading-normal">{t('onboarding.handoff_sub')}</p>
                </div>

                {onComputer ? (
                    <div className="flex max-w-[440px] flex-col gap-4">
                        <p className="text-ink-2 text-[15px] leading-normal">
                            {canPrompt ? t('onboarding.computer_prompt') : t('onboarding.computer_manual')}
                        </p>
                        <div className="flex flex-wrap gap-3">
                            {canPrompt && (
                                <Button variant="sun" size="cta" onClick={() => void promptInstall().then((ok) => ok && advance('desktop'))}>
                                    <Download />
                                    {t('onboarding.computer_install')}
                                </Button>
                            )}
                            <Button
                                variant={canPrompt ? 'outline' : 'sun'}
                                size="cta"
                                className={canPrompt ? 'rounded-full' : ''}
                                onClick={() => advance('desktop')}
                            >
                                {t('onboarding.computer_done')}
                                <ArrowRight />
                            </Button>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="flex max-w-[440px] items-center gap-5">
                            <div className="border-ink relative flex size-[196px] shrink-0 items-center justify-center rounded-[20px] border-[1.5px] bg-white p-3.5">
                                {svg ? (
                                    // The SVG comes from the qrcode library for our own URL, not from user input.
                                    <span className="size-[168px] [&_svg]:size-full" dangerouslySetInnerHTML={{ __html: svg }} />
                                ) : (
                                    <LoaderCircle className="text-stone size-6 animate-spin" />
                                )}
                                {svg && (
                                    <span className="bg-sun absolute flex size-10 items-center justify-center rounded-[11px] border-4 border-white">
                                        <AppLogoIcon className="fill-ink size-5" />
                                    </span>
                                )}
                            </div>
                            <ol className="flex flex-col gap-3.5">
                                {[
                                    [Camera, 'onboarding.handoff_1'],
                                    [SquarePlus, 'onboarding.handoff_2'],
                                    [BellRing, 'onboarding.handoff_3'],
                                ].map(([Icon, label]) => {
                                    const I = Icon as typeof Camera;
                                    return (
                                        <li key={label as string} className="flex items-center gap-3">
                                            <span className="bg-paper-2 flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                                                <I className="size-[17px]" />
                                            </span>
                                            <span className="text-[15px] leading-tight font-semibold">{t(label as string)}</span>
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>

                        <div className="flex max-w-[440px] flex-col gap-2.5">
                            <p className="text-stone text-[13px]">{t('onboarding.handoff_email_q')}</p>
                            <div className="flex gap-2">
                                {/* Read-only: the link signs this account in, so it only ever goes to the account's own inbox. */}
                                <Input
                                    readOnly
                                    value={email}
                                    aria-label={t('onboarding.handoff_email_q')}
                                    className="border-line h-[41px] flex-1 rounded-xl bg-white text-sm focus-visible:ring-0"
                                />
                                <Button variant="ink" disabled={sending || sent} onClick={sendLink} className="h-[41px] rounded-xl px-[18px]">
                                    {sent ? <Check /> : null}
                                    {sent ? t('onboarding.handoff_sent') : t('onboarding.handoff_send')}
                                </Button>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() => setOnComputer(true)}
                            className="text-ink-2 flex items-center gap-1.5 self-start text-sm font-semibold"
                        >
                            {t('onboarding.handoff_computer')}
                            <ArrowRight className="size-[15px]" />
                        </button>
                    </>
                )}
            </main>
            <aside className="bg-sun flex w-[380px] shrink-0 items-center justify-center max-xl:hidden">
                <div className="bg-ink w-[280px] rounded-[46px] p-[9px] shadow-[0_30px_60px_-20px_rgba(18,17,0,0.45)]">
                    <img
                        src="/images/onboarding/phone-dashboard.webp"
                        alt=""
                        className="aspect-[262/560] w-full rounded-[38px] object-cover object-top"
                    />
                </div>
            </aside>
        </div>
    );
}
