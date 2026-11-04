import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    ChevronDown,
    CircleAlert,
    Globe,
    Inbox,
    Keyboard,
    LoaderCircle,
    Mail,
    MailOpen,
    Smartphone,
    Sparkles,
    Timer,
} from 'lucide-react';
import { useEffect, useState, type FormEventHandler, type ReactNode } from 'react';

import { AppWordmark } from '@/components/app-logo';
import AppLogoIcon from '@/components/app-logo-icon';
import { Homer } from '@/components/homer';
import InputError from '@/components/input-error';
import LegalNote from '@/components/legal-note';
import SocialButtons from '@/components/social-buttons';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    // Same flow either way (a new address gets an account); the URL only picks the wording.
    mode: 'login' | 'register';
    status?: string;
    canDemo: boolean;
    // The address the last code went to: show the code box for it.
    codeSentTo?: string | null;
}

// The address a code last went to on this device, so "Welcome back" is one tap from a code (design A03/M03).
// localStorage on purpose: a convenience for this browser only, never sent anywhere until they press the button.
const REMEMBER_KEY = 'fw.lastEmail';
const remembered = () => {
    try {
        return localStorage.getItem(REMEMBER_KEY) ?? '';
    } catch {
        return '';
    }
};
// How long before "Resend" unlocks (design A02: "Resend in 0:42"). The server caps sends per address anyway
// (5 per 10 minutes); this only stops the reflex double-send while the first code is still on its way.
const RESEND_AFTER = 60;

// Webmail for the well-known providers (universal links: they open the Gmail / Outlook app on a phone), else the
// phone's own mail app: message:// on iOS, the APP_EMAIL intent on Android. null = nothing sensible to open.
function mailAppUrl(email: string): string | null {
    const domain = email.split('@')[1]?.toLowerCase() ?? '';
    if (/^(gmail|googlemail)\.com$/.test(domain)) return 'https://mail.google.com/mail/';
    if (/^(outlook|hotmail|live|msn)\./.test(domain)) return 'https://outlook.live.com/mail/';
    if (/^(yahoo|ymail)\./.test(domain)) return 'https://mail.yahoo.com/';
    const ua = navigator.userAgent;
    if (/iPhone|iPad|iPod/.test(ua)) return 'message://';
    if (/Android/.test(ua)) return 'intent://#Intent;action=android.intent.action.MAIN;category=android.intent.category.APP_EMAIL;end';
    return null;
}

// Sign in AND sign up (design "App · 00a · Sign up & Log in", A01-A03 desktop / M01-M03 phone): email -> 6-digit
// code by email -> in (AuthenticatedSessionController). No passwords. Paper form on the left, the sun panel on the
// right (photo + demo on sign up, the waiting tips on the code step); on a phone the form stands alone. Fixed
// paper / ink palette like the onboarding: the brand look doesn't follow the app's skins or dark mode.
export default function EmailCode({ mode, status, canDemo, codeSentTo }: Props) {
    const t = useT();
    const register = mode === 'register';
    // "Use a different email" goes back to step 1 without a server round trip.
    const [changing, setChanging] = useState(false);
    const askCode = !!codeSentTo && !changing;
    const [inviteHelp, setInviteHelp] = useState(false);

    // ponytail: TEMPORARY password sign-in for app reviewers (AuthenticatedSessionController::passwordLogin).
    // Remove withPassword, the password field and its link once the app directory approvals are through.
    const [withPassword, setWithPassword] = useState(false);
    const emailForm = useForm({ email: codeSentTo ?? '', password: '' });
    const codeForm = useForm({ code: '' });
    const wrong = !!codeForm.errors.code;

    // Log in pre-fills the address from last time; sign up starts empty (a new person, maybe on a shared device).
    const [prefilled, setPrefilled] = useState(false);
    useEffect(() => {
        const last = remembered();
        if (!register && last && !emailForm.data.email) {
            emailForm.setData('email', last);
            setPrefilled(true);
        }
        // Once, on load: later typing must never be overwritten.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const sendCode: FormEventHandler = (e) => {
        e.preventDefault();
        if (withPassword) {
            emailForm.post(route('login.password'), { onFinish: () => emailForm.reset('password') });
            return;
        }
        try {
            localStorage.setItem(REMEMBER_KEY, emailForm.data.email.trim());
        } catch {
            // Private mode / storage off: just no pre-fill next time.
        }
        emailForm.post(route('auth.code.send'), {
            preserveScroll: true,
            onSuccess: () => {
                setChanging(false);
                codeForm.reset();
                codeForm.clearErrors();
            },
        });
    };
    // Sends the code it's given, not codeForm.data: on the auto-submit after the sixth digit, setData hasn't
    // landed yet and the form would still hold five. A wrong code keeps its digits (design A02′: spot the typo).
    const verify = (code: string) => {
        codeForm.transform(() => ({ code }));
        codeForm.post(route('auth.code.verify'), { preserveScroll: true });
    };

    const switchLink = register
        ? { q: t('auth.already_have_account'), label: t('auth.log_in'), phone: t('auth.log_in'), href: route('login') }
        : { q: t('auth.new_to_familywell'), label: t('auth.create_account'), phone: t('auth.sign_up'), href: route('register') };

    return (
        <div className="bg-paper text-ink grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,500px)]">
            <Head title={register ? t('auth.create_account') : t('auth.log_in')} />

            {/* Phone: the sun's corner (design M01-M03), a background so nothing overflows; desktop has the whole right half instead. */}
            <div className="relative flex min-w-0 flex-col px-4 pt-5 pb-6 max-lg:bg-[radial-gradient(circle_at_calc(100%_+_16px)_-16px,var(--color-sun)_140px,transparent_141px)] md:px-16 md:pt-10">
                <header className="relative flex items-center justify-between gap-3">
                    <span className="flex items-center gap-3">
                        {askCode && (
                            <button
                                type="button"
                                onClick={() => setChanging(true)}
                                aria-label={t('auth.code_change_email')}
                                className="border-line flex size-11 items-center justify-center rounded-full border-2 bg-white md:hidden"
                            >
                                <ArrowLeft className="size-5" />
                            </button>
                        )}
                        <Logo />
                    </span>
                    {!askCode && (
                        <span className="flex items-center gap-3">
                            <span className="text-stone hidden text-[15px] md:inline">{switchLink.q}</span>
                            <Link
                                href={switchLink.href}
                                className="border-ink flex min-h-10 items-center rounded-full border-2 bg-white px-4 text-[15px] font-bold"
                            >
                                <span className="md:hidden">{switchLink.phone}</span>
                                <span className="hidden md:inline">{switchLink.label}</span>
                            </Link>
                        </span>
                    )}
                </header>

                <main className="relative mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center gap-6 py-8 md:py-12">
                    {askCode && (
                        <button
                            type="button"
                            onClick={() => setChanging(true)}
                            className="hidden items-center gap-2 self-start text-[15px] font-semibold md:flex"
                        >
                            <ArrowLeft className="size-4" />
                            {t('auth.code_change_email')}
                        </button>
                    )}
                    <div className="flex flex-col gap-3">
                        <span className="text-ink-2 font-mono text-[12px] font-semibold tracking-[1.6px] uppercase">
                            {askCode ? t('auth.eyebrow_code') : register ? t('auth.eyebrow_register') : t('auth.eyebrow_login')}
                        </span>
                        <h1 className="font-display text-[40px] leading-[1.02] font-bold tracking-[-1.6px] md:text-[48px]">
                            {askCode ? t('auth.code_title') : register ? t('auth.create_account_title') : t('auth.welcome_back')}
                        </h1>
                        <p className="text-ink-2 text-[17px] leading-normal">
                            {askCode ? t('auth.code_desc', { email: codeSentTo ?? '' }) : register ? t('auth.register_desc') : t('auth.signin_desc')}
                        </p>
                    </div>

                    {askCode ? (
                        <CodeStep
                            email={codeSentTo ?? ''}
                            code={codeForm.data.code}
                            wrong={wrong}
                            error={codeForm.errors.code}
                            processing={codeForm.processing}
                            onChange={(code) => {
                                codeForm.setData('code', code);
                                if (wrong) codeForm.clearErrors();
                                // Six digits is the whole answer: go on without making them find the button.
                                if (code.length === 6 && !codeForm.processing) verify(code);
                            }}
                            onSubmit={() => verify(codeForm.data.code)}
                            resending={emailForm.processing}
                            onResend={(done) =>
                                emailForm.post(route('auth.code.send'), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        codeForm.reset();
                                        codeForm.clearErrors();
                                        done();
                                    },
                                })
                            }
                            resendError={emailForm.errors.email}
                        />
                    ) : (
                        <>
                            <SocialButtons />
                            <form className="flex flex-col gap-4" onSubmit={sendCode}>
                                <div className="flex flex-col gap-2">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <label htmlFor="email" className="text-[15px] font-semibold">
                                            {t('auth.email_address')}
                                        </label>
                                        <span className="text-stone hidden font-mono text-[11px] tracking-[1.2px] uppercase sm:inline">
                                            {prefilled ? t('auth.email_hint_remembered') : t('auth.email_hint_code')}
                                        </span>
                                    </div>
                                    <span className="border-ink focus-within:ring-sun flex h-14 items-center gap-3 rounded-2xl border-2 bg-white px-4 focus-within:ring-4">
                                        <Mail className="text-ink-2 size-5 shrink-0" />
                                        <input
                                            id="email"
                                            type="email"
                                            required
                                            autoFocus
                                            autoComplete="email"
                                            // The phone keyboard with @ and .com ready (design M01).
                                            inputMode="email"
                                            value={emailForm.data.email}
                                            onChange={(e) => {
                                                emailForm.setData('email', e.target.value);
                                                setPrefilled(false);
                                            }}
                                            placeholder={t('auth.email_placeholder')}
                                            className="placeholder:text-stone/70 min-w-0 flex-1 bg-transparent text-[17px] outline-none"
                                        />
                                    </span>
                                    <InputError message={emailForm.errors.email} />
                                </div>

                                {withPassword && (
                                    <input
                                        type="password"
                                        required
                                        aria-label={t('auth.password')}
                                        placeholder={t('auth.password')}
                                        autoComplete="current-password"
                                        value={emailForm.data.password}
                                        onChange={(e) => emailForm.setData('password', e.target.value)}
                                        className="border-ink h-14 rounded-2xl border-2 bg-white px-4 text-[17px] outline-none"
                                    />
                                )}

                                <SunButton type="submit" disabled={emailForm.processing}>
                                    {emailForm.processing && <LoaderCircle className="size-5 animate-spin" />}
                                    {withPassword ? t('auth.password_sign_in') : t('auth.code_send')}
                                    <ArrowRight className="size-5" />
                                </SunButton>

                                {!register && (
                                    <button
                                        type="button"
                                        className="text-stone text-center text-sm underline underline-offset-4"
                                        onClick={() => setWithPassword(!withPassword)}
                                    >
                                        {withPassword ? t('auth.password_use_code') : t('auth.password_use')}
                                    </button>
                                )}
                            </form>
                            {register && <LegalNote />}
                        </>
                    )}

                    {status && <p className="text-ok-ink text-center text-sm font-medium">{status}</p>}
                </main>

                {/* Phone: Homer, the demo (sign up), then the invite line. Desktop: invite line + language. */}
                <div className="relative flex flex-col gap-4 lg:hidden">
                    {askCode ? (
                        <div className="flex flex-col items-center gap-3 md:hidden">
                            <Homer face={wrong ? 'neutral' : 'listening'} className="size-[120px]" />
                            {!wrong && (
                                <span className="text-ink-2 font-mono text-[12px] font-semibold tracking-[1.6px] uppercase">
                                    {t('auth.wait_title')}
                                </span>
                            )}
                        </div>
                    ) : (
                        <Homer face="happy" view="mirror" className="size-[110px] self-end" />
                    )}
                    {register && canDemo && !askCode && (
                        <div className="bg-ink flex items-center gap-3 rounded-[22px] p-4 text-white">
                            <Sparkles className="text-sun size-6 shrink-0" />
                            <span className="min-w-0 flex-1">
                                <span className="block text-[17px] font-bold">{t('auth.demo_short_title')}</span>
                                <span className="block text-sm text-white/75">{t('auth.demo_short_desc')}</span>
                            </span>
                            {/* A plain link (full page load) so the server can establish the demo session. */}
                            <a
                                href={route('demo.enter')}
                                className="bg-sun text-ink flex min-h-11 shrink-0 items-center gap-1.5 rounded-full px-4 font-bold"
                            >
                                {t('auth.demo_short_cta')}
                                <ArrowUpRight className="size-4" />
                            </a>
                        </div>
                    )}
                </div>
                <footer className="relative mt-4 flex items-center justify-center gap-4 md:justify-between">
                    <InviteLine onOpen={() => setInviteHelp(true)} />
                    <LanguagePicker />
                </footer>
            </div>

            <SunPanel register={register} askCode={askCode} wrong={wrong} canDemo={canDemo} />

            <ResponsiveModal open={inviteHelp} onOpenChange={setInviteHelp} title={t('auth.invite_help_title')}>
                <p className="text-muted-foreground text-[15px]">{t('auth.invite_help_text')}</p>
            </ResponsiveModal>
        </div>
    );
}

function Logo() {
    const { name } = usePage().props as { name?: string };
    // The FamilyWell wordmark; a self-hosted instance with its own APP_NAME keeps its name as text.
    const isBrandName = !name || name.toLowerCase() === 'familywell';
    return (
        // The logo leads to the public site (familywell.org): the app root only bounces guests back here.
        <a href="https://familywell.org" className="flex items-center gap-2.5">
            <span className="bg-sun flex size-9 items-center justify-center rounded-lg">
                <AppLogoIcon className="fill-ink size-5" />
            </span>
            {isBrandName ? <AppWordmark className="h-6 w-auto fill-current" /> : <span className="font-display text-2xl font-bold">{name}</span>}
        </a>
    );
}

function SunButton({ className, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={cn(
                'bg-sun border-ink text-ink flex h-14 w-full items-center justify-center gap-2.5 rounded-full border-2 text-[18px] font-bold disabled:opacity-60',
                className,
            )}
        />
    );
}

function InviteLine({ onOpen }: { onOpen: () => void }) {
    const t = useT();
    return (
        <p className="text-stone text-[15px]">
            {t('auth.invite_q')}{' '}
            <button type="button" onClick={onOpen} className="text-ink font-semibold">
                {t('auth.invite_join')}
            </button>
        </p>
    );
}

// Guests pick the page's language (desktop footer, design A01); kept in the session (SetLocale) and saved as theirs
// on sign-up. A native select: the OS picker on every device, no menu to build.
function LanguagePicker() {
    const t = useT();
    const { locale, locales } = usePage<{ locale: string; locales: { code: string; label: string }[] }>().props;
    return (
        <label className="bg-paper-2 relative hidden min-h-10 items-center gap-2 rounded-full px-4 text-[15px] md:flex">
            <Globe className="size-4" />
            <span>{locales.find((l) => l.code === locale)?.label}</span>
            <ChevronDown className="size-4" />
            <select
                aria-label={t('auth.language')}
                value={locale}
                onChange={(e) => router.post(route('auth.locale'), { locale: e.target.value }, { preserveScroll: true })}
                className="absolute inset-0 cursor-pointer opacity-0"
            >
                {locales.map((l) => (
                    <option key={l.code} value={l.code}>
                        {l.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

// The code step (A02 / A02′ / M02): six boxes over ONE real input, so iOS / Android one-time-code autofill and
// pasting the whole code still work (six separate inputs break both). The boxes only draw what the input holds.
function CodeStep({
    email,
    code,
    wrong,
    error,
    processing,
    onChange,
    onSubmit,
    resending,
    onResend,
    resendError,
}: {
    email: string;
    code: string;
    wrong: boolean;
    error?: string;
    processing: boolean;
    onChange: (code: string) => void;
    onSubmit: () => void;
    resending: boolean;
    onResend: (done: () => void) => void;
    resendError?: string;
}) {
    const t = useT();
    const [focused, setFocused] = useState(true);
    const [left, setLeft] = useState(RESEND_AFTER);
    useEffect(() => {
        if (left <= 0) return;
        const id = window.setTimeout(() => setLeft(left - 1), 1000);
        return () => window.clearTimeout(id);
    }, [left]);
    // Resend unlocks right away after a wrong code (A02′): the likeliest fix is a fresh one.
    const canResend = left <= 0 || wrong;
    const mail = mailAppUrl(email);

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit();
            }}
        >
            <div className="relative">
                <div className="grid grid-cols-6 gap-2 md:gap-3" aria-hidden>
                    {Array.from({ length: 6 }, (_, i) => {
                        const current = focused && !wrong && i === Math.min(code.length, 5) && code.length < 6;
                        return (
                            <span
                                key={i}
                                className={cn(
                                    'flex h-[62px] items-center justify-center rounded-2xl border-2 text-[28px] font-bold tabular-nums md:h-[66px]',
                                    wrong
                                        ? 'border-[#E8553D] bg-[#FFE4DE] text-[#C2401F]'
                                        : current
                                          ? 'border-ink bg-sun-soft'
                                          : code[i]
                                            ? 'border-ink bg-white'
                                            : 'border-line bg-white',
                                )}
                            >
                                {code[i] ?? (current ? <span className="bg-ink h-7 w-0.5 animate-pulse" /> : null)}
                            </span>
                        );
                    })}
                </div>
                {/* Native one-time-code field, invisible over the boxes: iOS / Android offer the emailed code above the keyboard. */}
                <input
                    aria-label={t('auth.code_label')}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]*"
                    maxLength={6}
                    autoFocus
                    required
                    value={code}
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    className="absolute inset-0 w-full cursor-text text-base caret-transparent opacity-0"
                />
            </div>

            {wrong ? (
                <p className="flex items-start gap-3 rounded-2xl bg-[#FFE4DE] px-4 py-3 text-[15px] text-[#C2401F]">
                    <CircleAlert className="mt-0.5 size-5 shrink-0" />
                    {error}
                </p>
            ) : (
                <p className="bg-paper-2 text-ink-2 flex items-center gap-3 rounded-2xl px-4 py-3 text-[15px] md:hidden">
                    <Keyboard className="size-5 shrink-0" />
                    {t('auth.code_tip')}
                </p>
            )}

            <SunButton type="submit" disabled={processing || code.length !== 6}>
                {processing && <LoaderCircle className="size-5 animate-spin" />}
                {wrong ? t('auth.code_try_again') : t('auth.code_continue')}
                <ArrowRight className="size-5" />
            </SunButton>

            {/* Phone: one button to the mail app; desktop: straight into the two big webmails. */}
            {mail && (
                <a
                    href={mail}
                    className="border-line flex h-14 items-center justify-center gap-2.5 rounded-full border-2 bg-white text-[17px] font-semibold md:hidden"
                >
                    <MailOpen className="size-5" />
                    {t('auth.open_mail')}
                </a>
            )}
            <div className="hidden grid-cols-2 gap-3 md:grid">
                {[
                    { href: 'https://mail.google.com/mail/', label: t('auth.open_gmail') },
                    { href: 'https://outlook.live.com/mail/', label: t('auth.open_outlook') },
                ].map((m) => (
                    <a
                        key={m.href}
                        href={m.href}
                        target="_blank"
                        rel="noopener"
                        className="border-line flex h-14 items-center justify-center gap-2.5 rounded-full border-2 bg-white text-[17px] font-semibold"
                    >
                        <MailOpen className="size-5" />
                        {m.label}
                    </a>
                ))}
            </div>

            <p className="text-stone text-center text-[15px]">
                {t('auth.didnt_get')}{' '}
                {canResend ? (
                    <button
                        type="button"
                        disabled={resending}
                        onClick={() => onResend(() => setLeft(RESEND_AFTER))}
                        className="text-ink font-semibold"
                    >
                        {t('auth.code_resend')}
                    </button>
                ) : (
                    <span className="text-ink font-mono font-semibold">{t('auth.resend_in', { time: `0:${String(left).padStart(2, '0')}` })}</span>
                )}
            </p>
            <InputError message={resendError} className="text-center" />
        </form>
    );
}

// The right half on desktop (lg+): sun yellow. Email step: eyebrow, headline, a cosy home photo, and the demo card
// on sign up. Code step: Homer waiting, and the three things that usually went wrong.
function SunPanel({ register, askCode, wrong, canDemo }: { register: boolean; askCode: boolean; wrong: boolean; canDemo: boolean }) {
    const t = useT();
    if (askCode) {
        const tips: [ReactNode, string][] = [
            [<Inbox key="i" className="size-5" />, t('auth.tip_spam')],
            [<Smartphone key="s" className="size-5" />, t('auth.tip_device')],
            [<Timer key="t" className="size-5" />, t('auth.tip_expired')],
        ];
        return (
            <aside className="bg-sun hidden flex-col items-center justify-center gap-6 p-12 text-center lg:flex">
                <Homer face={wrong ? 'neutral' : 'listening'} className="size-[200px]" />
                <div className="flex flex-col gap-2">
                    <span className="font-mono text-[12px] font-semibold tracking-[1.6px] uppercase">
                        {wrong ? t('auth.wrong_eyebrow') : t('auth.wait_eyebrow')}
                    </span>
                    <h2 className="font-display text-[32px] leading-tight font-bold tracking-[-1.2px]">
                        {wrong ? t('auth.wrong_title') : t('auth.wait_title')}
                    </h2>
                </div>
                <ul className="bg-paper border-ink divide-line w-full divide-y overflow-hidden rounded-[22px] border-2 text-left">
                    {tips.map(([icon, text]) => (
                        <li key={text} className="flex items-start gap-3 px-4 py-3.5 text-[15px]">
                            <span className="mt-0.5 shrink-0">{icon}</span>
                            {text}
                        </li>
                    ))}
                </ul>
            </aside>
        );
    }
    // Photos of cosy home interiors generated for FamilyWell (public/images/auth): morning kitchen (sign up),
    // evening living room (log in).
    const p = register ? 'register' : 'login';
    return (
        // Pinned to the viewport height so the photo shrinks to fit instead of making the page scroll.
        <aside className="bg-sun sticky top-0 hidden h-dvh flex-col gap-6 p-12 lg:flex">
            <div className="flex flex-col gap-3">
                <span className="font-mono text-[12px] font-semibold tracking-[1.6px] uppercase">{t(`auth.panel_${p}_eyebrow`)}</span>
                <h2 className="font-display text-[38px] leading-[1.04] font-bold tracking-[-1.4px]">{t(`auth.panel_${p}_title`)}</h2>
                <p className="text-ink-2 text-[17px] leading-normal">{t(`auth.panel_${p}_sub`)}</p>
            </div>
            <img src={`/images/auth/${p}.webp`} alt="" className="border-ink h-0 min-h-40 w-full flex-1 rounded-[22px] border-2 object-cover" />
            {register && canDemo && (
                <div className="bg-ink relative flex flex-col gap-3 overflow-hidden rounded-[22px] p-6 text-white">
                    <span className="flex items-center gap-2.5 pr-24 text-[20px] font-bold">
                        <Sparkles className="text-sun size-6 shrink-0" />
                        {t('auth.demo_title')}
                    </span>
                    <p className="pr-24 text-[15px] text-white/75">{t('auth.demo_desc')}</p>
                    <a
                        href={route('demo.enter')}
                        className="bg-sun text-ink flex min-h-11 items-center gap-2 self-start rounded-full px-5 text-[16px] font-bold"
                    >
                        {t('auth.demo_cta')}
                        <ArrowUpRight className="size-4" />
                    </a>
                    <Homer face="happy" onDark className="absolute right-4 bottom-4 size-[96px]" />
                </div>
            )}
        </aside>
    );
}
