import { Head, router } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { SkinPicker, type Skin } from '@/components/skin-picker';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

export default function Appearance({
    themes = [],
    currentTheme = null,
    familyDefault = 'minimal-mono',
}: {
    themes?: Skin[];
    currentTheme?: string | null; // null => inherit the family default
    familyDefault?: string;
}) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.appearance_settings'), href: '/settings/appearance' }];

    const familySkin = themes.find((s) => s.id === familyDefault);
    // Optimistic highlight only: the skin CSS is injected server-side, so colours switch when the reload lands.
    const picked = useOptimistic<string | null>();
    const selectSkin = (id: string | null) => {
        picked.set('theme', id);
        router.patch(
            route('appearance.theme'),
            { theme: id },
            { preserveScroll: true, preserveState: true, onSuccess: () => picked.clear('theme'), onError: () => picked.clear('theme') },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.appearance_settings')} />

            <SettingsLayout>
                <div className="space-y-8">
                    <div className="space-y-6">
                        <HeadingSmall title={t('settings.appearance_settings')} description={t('settings.appearance_settings_desc')} />
                        <AppearanceTabs />
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall title={t('settings.skin')} description={t('settings.skin_desc')} />
                        <SkinPicker
                            themes={themes}
                            value={picked.get('theme', currentTheme)}
                            onSelect={selectSkin}
                            inheritLabel={t('settings.skin_family_default', { name: familySkin?.name ?? familyDefault })}
                            inheritSwatch={familySkin?.swatch}
                        />
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
