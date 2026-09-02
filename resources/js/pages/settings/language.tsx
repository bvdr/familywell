import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';

import HeadingSmall from '@/components/heading-small';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

type Locale = { code: string; label: string };

export default function Language({ locales, current }: { locales: Locale[]; current: string }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.language'), href: '/settings/language' }];

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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.language')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.language')} description={t('settings.language_desc')} />
                    <div className="bg-surface-card divide-border/60 divide-y rounded-xl border">
                        {locales.map((l) => {
                            const on = l.code === selected;
                            return (
                                <button
                                    key={l.code}
                                    type="button"
                                    onClick={() => choose(l.code)}
                                    className="flex w-full items-center justify-between px-4 py-3 text-left"
                                >
                                    <span className="text-primary text-sm font-medium">{l.label}</span>
                                    {on && <Check className="text-primary size-4" />}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
