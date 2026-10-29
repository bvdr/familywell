import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { FamilyLookPicker } from '@/components/family-look-picker';
import { FamilyMark } from '@/components/family-mark';
import { FamilySkinList, type FamilySkin } from '@/components/family-skin-list';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/settings-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useSkinCss } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Props = {
    family: { name: string; color: string; symbol: string | null; emoji: string; description: string | null; theme: string };
    canManage: boolean;
    members: number;
    colors: string[];
    themes: FamilySkin[];
    defaultTheme: string;
    themeCss?: string;
};

const LABEL = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase';

// Settings > Family > Family name & look (boards Y1/Y5): name, colour and symbol with a live preview, the same
// pickers as onboarding step 1 (FamilyLookPicker), then the family skin. Admins edit; everyone else reads.
export default function FamilyLook({ family, canManage, members, colors, themes, defaultTheme, themeCss }: Props) {
    const t = useT();
    useSkinCss(themeCss);
    // update() wants the whole family record, so emoji + description go back unchanged.
    const form = useForm({
        name: family.name,
        color: family.color,
        symbol: family.symbol,
        emoji: family.emoji,
        description: family.description ?? '',
    });
    const { data, setData } = form;
    const displayName = data.name.trim() || family.name;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.patch(route('family.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('settings_hub.family_look'), href: '/settings/family/look' }]}>
            <Head title={t('settings_hub.family_look')} />
            <SettingsPage back={{ href: '/settings/family', label: family.name }} title={t('settings_hub.family_look')}>
                <form onSubmit={submit} className="flex flex-col gap-6">
                    {/* Live preview, onboarding step 1's card: the mark in the family's colour, its name, who's in it. */}
                    <div className="bg-surface-card flex items-center gap-3.5 rounded-[22px] border p-4">
                        <FamilyMark
                            name={displayName}
                            color={data.color}
                            symbol={data.symbol}
                            emoji={data.emoji}
                            className="border-primary size-14 rounded-[18px] border-[1.5px] text-2xl [&_svg]:size-7"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="font-display text-primary truncate text-xl font-bold tracking-[-0.4px]">{displayName}</p>
                            <p className="text-muted-foreground truncate text-[13px]">{t('settings_hub.people', { count: members })}</p>
                        </div>
                        <span className="text-muted-foreground font-mono text-[10px] font-semibold tracking-[0.8px] uppercase">
                            {t('settings_look.preview')}
                        </span>
                    </div>

                    <div className="flex flex-col gap-2.5">
                        <label htmlFor="name" className={LABEL}>
                            {t('onboarding.family_name')}
                        </label>
                        <Input
                            id="name"
                            required
                            maxLength={255}
                            disabled={!canManage}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="border-primary focus-visible:border-primary bg-surface-card h-12 rounded-[14px] border-[1.5px] px-4 text-base font-medium focus-visible:ring-0 focus-visible:ring-offset-0"
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="flex flex-col gap-3">
                        <span className={LABEL}>{t('onboarding.color_symbol')}</span>
                        <FamilyLookPicker
                            colors={colors}
                            color={data.color}
                            symbol={data.symbol ?? ''}
                            name={displayName}
                            disabled={!canManage}
                            onPick={(key, value) => setData(key, value)}
                        />
                        <InputError message={form.errors.color || form.errors.symbol || form.errors.emoji} />
                    </div>

                    {canManage ? (
                        <div className="flex items-center gap-3">
                            <Button type="submit" size="lg" className="flex-1 md:flex-none" disabled={!form.isDirty || form.processing}>
                                {t('settings_look.save')}
                            </Button>
                            {form.recentlySuccessful && <span className="text-muted-foreground text-sm">{t('settings_look.saved')}</span>}
                        </div>
                    ) : (
                        <p className="text-muted-foreground px-1 text-sm">{t('settings_look.look_admin_only')}</p>
                    )}
                </form>

                <FamilySkinList themes={themes} value={family.theme} defaultTheme={defaultTheme} canManage={canManage} />
            </SettingsPage>
        </AppLayout>
    );
}
