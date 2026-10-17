import { Head, router, useForm } from '@inertiajs/react';
import { Check, LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import AppearanceTabs from '@/components/appearance-tabs';
import InputError from '@/components/input-error';
import { SkinPicker, type Skin } from '@/components/skin-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSkinCss } from '@/hooks/use-appearance';
import { useOptimistic } from '@/hooks/use-optimistic';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';
import { useT } from '@/lib/i18n';

type Locale = { code: string; label: string };

interface Props {
    name: string | null;
    locales: Locale[];
    currentLocale: string;
    themes: Skin[];
    currentTheme: string | null;
    themeCss?: string;
}

// "About you": the first step after sign-up (OnboardingController::about). Language and colours save the moment
// they're picked, through the same endpoints as Settings, so the page re-renders in that language / skin right
// away and they see what they chose. Only the name waits for the button.
export default function AboutYou({ name, locales, currentLocale, themes, currentTheme, themeCss }: Props) {
    const t = useT();
    useSkinCss(themeCss);
    const form = useForm({ name: name ?? '' });
    const picked = useOptimistic<string | null>();

    const pick = (key: 'locale' | 'theme', value: string | null, url: string) => {
        picked.set(key, value);
        // preserveState keeps the typed name while the page reloads in the new language / skin.
        router.patch(url, { [key]: value }, { preserveScroll: true, preserveState: true, onFinish: () => picked.clear(key) });
    };
    const locale = picked.get('locale', currentLocale);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('onboarding.about.store'));
    };

    return (
        <AuthSplitLayout headline={t('onboarding.about_headline')} sub={t('onboarding.about_sub')} image="/images/auth/register.webp">
            <Head title={t('onboarding.about_title')} />

            <div className="flex flex-col gap-2">
                <h1 className="font-heading text-primary text-[32px] leading-tight">{t('onboarding.about_title')}</h1>
                <p className="text-muted-foreground text-[15px]">{t('onboarding.about_desc')}</p>
            </div>

            <form className="flex flex-col gap-8" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="name">{t('onboarding.your_name')}</Label>
                    <Input
                        id="name"
                        autoFocus={!name}
                        required
                        autoComplete="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder={t('onboarding.your_name_placeholder')}
                    />
                    <InputError message={form.errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label>{t('onboarding.language')}</Label>
                    <div className="bg-surface-card divide-border/60 divide-y rounded-xl border">
                        {locales.map((l) => (
                            <button
                                key={l.code}
                                type="button"
                                onClick={() => l.code !== locale && pick('locale', l.code, route('language.update'))}
                                className="flex w-full items-center justify-between px-4 py-3 text-left"
                            >
                                <span className="text-primary text-sm font-medium">{l.label}</span>
                                {l.code === locale && <Check className="text-primary size-4" />}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-3">
                    <Label>{t('onboarding.colours')}</Label>
                    <AppearanceTabs />
                    <SkinPicker
                        themes={themes}
                        value={picked.get('theme', currentTheme)}
                        onSelect={(id) => pick('theme', id, route('appearance.theme'))}
                    />
                </div>

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {form.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {t('onboarding.continue')}
                </Button>
            </form>
        </AuthSplitLayout>
    );
}
