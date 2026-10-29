import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';

import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Locale = { code: string; label: string; english: string };

// Settings > Language (design M5): tapping a language switches right away, no save button.
export default function Language({ locales, current }: { locales: Locale[]; current: string }) {
    const t = useT();

    // Saving reloads (Inertia back()) so the new locale's translations arrive on the shared prop. The tick
    // moves optimistically; the strings themselves can only change once the reload lands.
    const picked = useOptimistic<string>();
    const selected = picked.get('locale', current);
    const choose = (code: string) => {
        if (code === selected) return;
        picked.set('locale', code);
        router.patch(
            route('language.update'),
            { locale: code },
            { preserveScroll: true, preserveState: true, onSuccess: () => picked.clear('locale'), onError: () => picked.clear('locale') },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('settings_hub.language'), href: '/settings/language' }]}>
            <Head title={t('settings_hub.language')} />
            <SettingsPage
                back={{ href: '/settings', label: t('settings_hub.me') }}
                title={t('settings_hub.language')}
                lede={t('settings_me.language_lede')}
            >
                <SettingsGroup>
                    {locales.map((l) => {
                        const on = l.code === selected;
                        return (
                            <SettingsRow
                                key={l.code}
                                title={<span className="font-semibold">{l.label}</span>}
                                // The English name under the native one, unless they're the same word (English).
                                sub={l.english !== l.label ? l.english : undefined}
                                onClick={() => choose(l.code)}
                                right={
                                    <span
                                        className={cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                                            on ? 'border-primary text-primary' : 'border-border',
                                        )}
                                    >
                                        {on && <Check className="size-3.5" strokeWidth={3} />}
                                    </span>
                                }
                            />
                        );
                    })}
                </SettingsGroup>
            </SettingsPage>
        </AppLayout>
    );
}
