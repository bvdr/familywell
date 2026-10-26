import { Head, router, useForm } from '@inertiajs/react';
import { ArrowRight, Bell, Check, ChevronDown, Globe, LoaderCircle, Monitor, Moon, Plus, Sun, type LucideIcon } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { type Skin } from '@/components/skin-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useAppearance, useSkinCss, type Appearance } from '@/hooks/use-appearance';
import { useOptimistic } from '@/hooks/use-optimistic';
import { Logo } from '@/layouts/onboarding-layout';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Locale = { code: string; label: string };
type Theme = Skin & { swatchDark: Skin['swatch']; badge: string | null };

interface Props {
    name: string | null;
    locales: Locale[];
    currentLocale: string;
    themes: Theme[];
    currentTheme: string | null;
    themeCss?: string;
}

// The skin people get when they haven't picked one (ThemeTokens::resolve's default).
const DEFAULT_THEME = 'minimal-mono';

// "About you" (design QJb8p desktop / P61NK phone): the first step after sign-up (OnboardingController::about).
// Language and colours save the moment they're picked, through the same endpoints as Settings, so the page comes
// back in that language and the live preview shows that skin; only the name waits for Continue.
export default function AboutYou({ name, locales, currentLocale, themes, currentTheme, themeCss }: Props) {
    const t = useT();
    const locale = useLocale();
    useSkinCss(themeCss);
    const form = useForm({ name: name ?? '' });
    const picked = useOptimistic<string | null>();
    const { appearance, updateAppearance } = useAppearance();
    const [langOpen, setLangOpen] = useState(false);

    const pick = (key: 'locale' | 'theme', value: string | null, url: string) => {
        picked.set(key, value);
        // preserveState keeps the typed name while the page reloads in the new language / skin.
        router.patch(url, { [key]: value }, { preserveScroll: true, preserveState: true, onFinish: () => picked.clear(key) });
    };
    const lang = picked.get('locale', currentLocale);
    const themeId = picked.get('theme', currentTheme) ?? DEFAULT_THEME;
    const theme = themes.find((x) => x.id === themeId) ?? themes[0];
    const langLabel = locales.find((l) => l.code === lang)?.label ?? lang;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('onboarding.about.store'));
    };

    const pickLang = (code: string) => {
        setLangOpen(false);
        if (code !== lang) pick('locale', code, route('language.update'));
    };

    const field = (label: string, hint: React.ReactNode, children: React.ReactNode, id?: string) => (
        <div className="flex flex-col gap-2">
            <div className="flex items-baseline justify-between gap-3">
                <label htmlFor={id} className="text-[13px] font-semibold">
                    {label}
                </label>
                <span className="text-stone font-mono text-[10.5px] font-semibold tracking-[0.6px] uppercase">{hint}</span>
            </div>
            {children}
        </div>
    );

    const continueButton = (
        <Button type="submit" variant="sun" size="cta" className="w-full md:w-auto md:min-w-[220px]" disabled={form.processing}>
            {t('onboarding.continue')}
            {form.processing ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
        </Button>
    );

    return (
        <form onSubmit={submit} className="bg-paper text-ink flex min-h-dvh font-sans">
            <Head title={t('onboarding.about_title')} />

            <main className="flex min-w-0 flex-1 flex-col">
                <div className="flex flex-col gap-[22px] px-5 pt-5 md:px-14 md:pt-10 lg:max-w-[800px] lg:px-16 lg:pt-10">
                    <Logo />
                    <div className="flex flex-col gap-2.5">
                        <p className="text-stone font-mono text-xs font-semibold tracking-[0.8px] uppercase">{t('onboarding.about_eyebrow')}</p>
                        <h1 className="font-display text-[34px] leading-none font-bold tracking-[-1.2px] md:text-[44px] md:tracking-[-1.6px]">
                            {t('onboarding.about_heading')}
                        </h1>
                        <p className="text-ink-2 text-[15px] leading-normal md:text-base">{t('onboarding.about_desc')}</p>
                    </div>

                    {field(
                        t('onboarding.your_name'),
                        t('onboarding.about_hint_name'),
                        <>
                            <Input
                                id="name"
                                autoFocus={!name}
                                required
                                autoComplete="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('onboarding.your_name_placeholder')}
                                className="border-ink focus-visible:border-ink h-12 rounded-[14px] border-[1.5px] bg-white px-4 text-base font-medium focus-visible:ring-0 focus-visible:ring-offset-0"
                            />
                            <InputError message={form.errors.name} />
                        </>,
                        'name',
                    )}

                    {field(
                        t('onboarding.language'),
                        // Where the starting language came from: the phone / browser it was set to.
                        <>
                            <span className="lg:hidden">{t('onboarding.about_hint_lang_phone')}</span>
                            <span className="hidden lg:inline">{t('onboarding.about_hint_lang_browser')}</span>
                        </>,
                        <>
                            {/* Phone / tablet: one row that opens the list. */}
                            <button
                                type="button"
                                onClick={() => setLangOpen(true)}
                                className="border-line flex h-[52px] items-center gap-3 rounded-[14px] border bg-white px-4 text-left lg:hidden"
                            >
                                <Globe className="size-[18px]" />
                                <span className="flex-1 text-base font-medium">{langLabel}</span>
                                <span className="text-stone text-[13px]">{t('onboarding.about_languages', { count: locales.length })}</span>
                                <ChevronDown className="size-4" />
                            </button>
                            {/* Desktop: every language at once. */}
                            <div className="hidden grid-cols-3 gap-2 lg:grid">
                                {locales.map((l) => (
                                    <button
                                        key={l.code}
                                        type="button"
                                        onClick={() => pickLang(l.code)}
                                        className={cn(
                                            'flex h-10 items-center justify-between rounded-xl border px-3.5 text-left text-sm',
                                            l.code === lang ? 'border-ink bg-sun-soft border-[1.5px] font-bold' : 'border-line bg-white font-medium',
                                        )}
                                    >
                                        {l.label}
                                        {l.code === lang && <Check className="size-4" />}
                                    </button>
                                ))}
                            </div>
                        </>,
                    )}

                    {field(
                        t('onboarding.colours'),
                        t('onboarding.about_hint_colours'),
                        <div className="flex flex-col gap-2.5">
                            <ModeSwitch value={appearance} onChange={updateAppearance} />
                            <div className="grid grid-cols-3 gap-2">
                                {/* A new skin first (the design leads with Sun), then the rest as the server lists them. */}
                                {[...themes]
                                    .sort((a, b) => Number(b.badge === 'new') - Number(a.badge === 'new'))
                                    .map((s) => {
                                        const on = s.id === themeId;
                                        return (
                                            <button
                                                key={s.id}
                                                type="button"
                                                onClick={() => !on && pick('theme', s.id, route('appearance.theme'))}
                                                className={cn(
                                                    'flex flex-col gap-2 rounded-2xl border p-2.5 text-left',
                                                    on ? 'border-ink bg-sun-soft border-[1.5px]' : 'border-line bg-white',
                                                )}
                                            >
                                                <span
                                                    className="bg-paper-2 flex h-10 items-center gap-2 rounded-xl px-3"
                                                    style={{ background: s.swatch.card }}
                                                >
                                                    <span className="size-2.5 shrink-0 rounded-full" style={{ background: s.swatch.accent }} />
                                                    <span className="h-1 flex-1 rounded-full" style={{ background: s.swatch.ink }} />
                                                    {on && (
                                                        <span className="bg-ink text-sun flex size-5 shrink-0 items-center justify-center rounded-full">
                                                            <Check className="size-3" strokeWidth={3} />
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="flex items-center gap-1.5 text-[13px] font-bold">
                                                    <span className="truncate">{s.name}</span>
                                                    {s.badge === 'new' && (
                                                        <span className="bg-ink text-sun rounded-full px-1.5 py-px font-mono text-[8.5px] font-bold tracking-[0.5px]">
                                                            {t('onboarding.badge_new')}
                                                        </span>
                                                    )}
                                                </span>
                                            </button>
                                        );
                                    })}
                            </div>
                        </div>,
                    )}
                </div>

                <div className="bg-paper sticky bottom-0 mt-auto flex flex-col items-center gap-3 px-5 pt-6 pb-[calc(24px+env(safe-area-inset-bottom))] md:flex-row md:gap-6 md:px-14 lg:static lg:px-16 lg:pb-9">
                    {continueButton}
                    <span className="text-stone text-xs md:text-[13px]">{t('onboarding.about_later')}</span>
                </div>
            </main>

            {/* Desktop: the live preview on the sun panel, in the picked language, skin and light / dark. */}
            <aside className="bg-sun sticky top-0 hidden h-dvh w-[480px] shrink-0 flex-col justify-center gap-4 px-12 lg:flex">
                <span className="font-mono text-[11px] font-semibold tracking-[0.8px] uppercase">
                    {[t('onboarding.live_preview'), theme?.name, t(`settings.${appearance}`), langLabel].join(' · ')}
                </span>
                {theme && (
                    <PreviewCard
                        name={form.data.name.trim().split(/\s+/)[0] || t('onboarding.about_preview_you')}
                        swatch={isDark(appearance) ? theme.swatchDark : theme.swatch}
                        locale={locale}
                    />
                )}
                <p className="text-ink-2 text-[13px] leading-normal">{t('onboarding.about_preview_note')}</p>
            </aside>

            <ResponsiveModal open={langOpen} onOpenChange={setLangOpen} title={t('onboarding.language')} className="bg-paper text-ink">
                <div className="flex flex-col">
                    {locales.map((l) => (
                        <button
                            key={l.code}
                            type="button"
                            onClick={() => pickLang(l.code)}
                            className="border-line flex items-center justify-between border-b py-3.5 text-left text-base last:border-0"
                        >
                            <span className={cn(l.code === lang && 'font-bold')}>{l.label}</span>
                            {l.code === lang && <Check className="size-4" />}
                        </button>
                    ))}
                </div>
            </ResponsiveModal>
        </form>
    );
}

// Light / Dark / System, drawn like the design's segmented control (the app's AppearanceTabs is skin-coloured).
function ModeSwitch({ value, onChange }: { value: Appearance; onChange: (a: Appearance) => void }) {
    const t = useT();
    const modes: [Appearance, LucideIcon][] = [
        ['light', Sun],
        ['dark', Moon],
        ['system', Monitor],
    ];
    return (
        <div className="bg-paper-2 grid grid-cols-3 rounded-full p-1" role="radiogroup" aria-label={t('onboarding.colours')}>
            {modes.map(([m, Icon]) => (
                <button
                    key={m}
                    type="button"
                    role="radio"
                    aria-checked={value === m}
                    onClick={() => onChange(m)}
                    className={cn(
                        'flex items-center justify-center gap-1.5 rounded-full py-2 text-[13px]',
                        value === m ? 'bg-white font-bold' : 'text-stone font-medium',
                    )}
                >
                    <Icon className="size-4" />
                    {t(`settings.${m}`)}
                </button>
            ))}
        </div>
    );
}

const isDark = (a: Appearance) =>
    a === 'dark' || (a === 'system' && typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches);

// A slice of their home in the picked skin: avatar + name, the greeting (in the picked language, from the real
// translations), today's line and a short list. Colours come from the skin's swatch, so it changes as they pick.
function PreviewCard({ name, swatch, locale }: { name: string; swatch: Skin['swatch']; locale: string }) {
    const t = useT();
    const [today, setToday] = useState('');
    useEffect(() => setToday(new Date().toLocaleDateString(locale, { weekday: 'long', month: 'short', day: 'numeric' })), [locale]);
    const items: [string, boolean][] = [
        [t('onboarding.about_item_1'), true],
        [t('onboarding.about_item_2'), false],
        [t('onboarding.about_item_3'), false],
    ];
    return (
        <div className="border-ink flex flex-col gap-4 rounded-[22px] border-[1.5px] p-6" style={{ background: swatch.bg, color: swatch.ink }}>
            <div className="flex items-center gap-2.5">
                <span
                    className="border-ink text-ink flex size-9 items-center justify-center rounded-full border-[1.5px] text-sm font-bold"
                    style={{ background: swatch.accent }}
                >
                    {name[0]?.toUpperCase()}
                </span>
                <span className="flex-1 text-[15px] font-semibold">{name}</span>
                <Bell className="size-4 opacity-70" />
            </div>
            <p className="font-display text-[26px] leading-tight font-bold tracking-[-0.8px]">{t('onboarding.preview_greeting', { name })}</p>
            <p className="font-mono text-[11px] tracking-[0.6px] uppercase opacity-60">
                {today} · {t('onboarding.about_today', { count: 3 })}
            </p>
            <div className="overflow-hidden rounded-2xl border" style={{ background: swatch.card, borderColor: `${swatch.ink}22` }}>
                {items.map(([label, done]) => (
                    <div key={label} className="flex items-center gap-3 border-b px-4 py-3 last:border-0" style={{ borderColor: `${swatch.ink}14` }}>
                        <span
                            className="flex size-5 items-center justify-center rounded-full border-[1.5px]"
                            style={{ borderColor: swatch.ink, background: done ? swatch.ink : 'transparent', color: swatch.accent }}
                        >
                            {done && <Check className="size-3" strokeWidth={3} />}
                        </span>
                        <span className={cn('text-[15px]', done && 'opacity-60')}>{label}</span>
                    </div>
                ))}
            </div>
            <span
                className="border-ink text-ink inline-flex w-fit items-center gap-1.5 rounded-full border-[1.5px] px-4 py-2 text-sm font-bold"
                style={{ background: swatch.accent }}
            >
                <Plus className="size-4" />
                {t('onboarding.about_add')}
            </span>
        </div>
    );
}
