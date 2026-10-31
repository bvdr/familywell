import { Head } from '@inertiajs/react';

import { FamilySkinList, SkinScreen, type FamilySkin } from '@/components/family-skin-list';
import { SettingsPage } from '@/components/settings-ui';
import { useAppearance, useSkinCss, type Appearance as Mode } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Props = {
    themes: FamilySkin[];
    /** The family's skin; null when the person has no family yet (no skin list then). */
    familySkin: string | null;
    defaultTheme: string;
    canManage: boolean;
    themeCss?: string; // the skin CSS in effect; applied in place by useSkinCss after a skin pick
};

// Me > Appearance (board M4). Light / dark / system is the person's own (stored in this browser by use-appearance);
// the skin is the family's, the same for everyone, so it is shown here but only its admins can change it. The old
// personal skin override (users.theme) is no longer offered: see ThemeTokens::resolve.
export default function Appearance({ themes, familySkin, defaultTheme, canManage, themeCss }: Props) {
    const t = useT();
    useSkinCss(themeCss);
    const { appearance, updateAppearance } = useAppearance();
    // The mode previews are drawn in the family's skin, so they show what this person will actually get.
    const skin = themes.find((s) => s.id === (familySkin ?? defaultTheme)) ?? themes[0];

    const modes: { value: Mode; label: string }[] = [
        { value: 'light', label: t('settings_hub.mode_light') },
        { value: 'dark', label: t('settings_hub.mode_dark') },
        { value: 'system', label: t('settings_hub.mode_system') },
    ];

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('settings_hub.appearance'), href: '/settings/appearance' }]}>
            <Head title={t('settings_hub.appearance')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('settings_hub.appearance')}>
                <section className="flex flex-col gap-2.5">
                    <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('settings_look.mode_label')}
                    </h2>
                    <div className="grid grid-cols-3 gap-3" role="radiogroup" aria-label={t('settings_look.mode_label')}>
                        {modes.map(({ value, label }) => {
                            const on = appearance === value;
                            return (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={on}
                                    onClick={() => updateAppearance(value)}
                                    className="flex min-w-0 flex-col items-center gap-2"
                                >
                                    <span
                                        className={cn(
                                            'relative block aspect-[11/12] w-full overflow-hidden rounded-[18px]',
                                            on ? 'border-primary border-2' : 'border',
                                        )}
                                    >
                                        {skin && (
                                            <>
                                                <SkinScreen
                                                    s={value === 'dark' ? skin.swatchDark : skin.swatch}
                                                    className="absolute inset-0 p-[11%]"
                                                />
                                                {/* System: light on the left, dark on the right, like the design. */}
                                                {value === 'system' && (
                                                    <SkinScreen
                                                        s={skin.swatchDark}
                                                        className="absolute inset-0 p-[11%] [clip-path:inset(0_0_0_50%)]"
                                                    />
                                                )}
                                            </>
                                        )}
                                    </span>
                                    <span className={cn('text-primary text-base', on && 'font-semibold')}>{label}</span>
                                </button>
                            );
                        })}
                    </div>
                </section>

                {familySkin && <FamilySkinList themes={themes} value={familySkin} defaultTheme={defaultTheme} canManage={canManage} />}
            </SettingsPage>
        </AppLayout>
    );
}
