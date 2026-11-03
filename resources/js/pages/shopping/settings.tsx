import { Head, router } from '@inertiajs/react';
import { Archive, ArrowDownUp, CircleCheck, Copy, MapPin, Mic, Sparkles, Store, Sun, Timer, Trash2, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

import { SettingsGroup, SettingsPage, SettingsRow, SettingsSwitch } from '@/components/settings-ui';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { getVoiceLang, setVoiceLang, VOICE_LANGS } from '@/lib/shopping';

type Settings = {
    default_shop: string | null;
    sort: 'aisle' | 'az' | 'added';
    bought: 'sink' | 'hide';
    ai_sort: boolean;
    warn_duplicates: boolean;
    keep_awake: boolean;
    auto_clean: boolean;
    clean_after_days: number;
};
type Pick = { key: keyof Settings | 'voice'; title: string; options: { value: string; label: string }[]; value: string };

// Shopping settings (design S1d), the gear next to the Shopping title: your shops, what every new list starts with,
// the helpers, clean-up and the archive. Family-wide (ShoppingSetting); each list can still change its own. A row
// with choices pushes a one-tap picker; switches save on tap. The voice language stays per device (the mic's).
export default function ShoppingSettings({
    settings,
    shops,
    archived,
    aiEnabled,
}: {
    settings: Settings;
    shops: string[];
    archived: number;
    aiEnabled: boolean;
}) {
    const t = useT();
    const [s, setS] = useState(settings);
    const [voice, setVoice] = useState(getVoiceLang);
    const [pick, setPick] = useState<Pick | null>(null);
    // Optimistic: the row changes at once, the save runs in the background (lib/save).
    const set = (patch: Partial<Settings>) => {
        setS((v) => ({ ...v, ...patch }));
        void save('patch', route('shopping.settings.update'), patch);
    };
    const choose = (value: string) => {
        if (!pick) return;
        if (pick.key === 'voice') {
            setVoice(value);
            setVoiceLang(value); // the mic's language: per device
        } else if (pick.key === 'clean_after_days') set({ clean_after_days: Number(value) });
        else if (pick.key === 'default_shop') set({ default_shop: value || null });
        else set({ [pick.key]: value } as Partial<Settings>);
        setPick(null);
    };
    const sortLabel = { aisle: t('shopping.sort_aisle'), az: t('shopping.sort_az'), added: t('shopping.sort_added') };
    const boughtLabel = { sink: t('shopping.bought_sink'), hide: t('shopping.bought_hide') };
    const days = (n: number) => t('shopping.n_days', { count: n });
    const voiceName = VOICE_LANGS.find((l) => l.code === voice)?.label ?? voice;
    const toggle = (icon: LucideIcon, key: 'ai_sort' | 'warn_duplicates' | 'keep_awake', title: string) => (
        <SettingsRow icon={icon} title={title} right={<SettingsSwitch on={s[key]} onToggle={() => set({ [key]: !s[key] })} label={title} />} />
    );

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('shopping.shopping_settings'), href: '/m/shopping/settings' }]}>
            <Head title={t('shopping.shopping_settings')} />
            <SettingsPage back={{ href: '/m/shopping', label: t('nav.shopping') }} title={t('shopping.shopping_settings')}>
                <SettingsGroup label={t('shopping.s_shops')}>
                    <SettingsRow icon={Store} title={t('shopping.your_shops')} value={String(shops.length)} href="/m/shopping/settings/shops" />
                </SettingsGroup>

                <SettingsGroup label={t('shopping.s_new_lists')}>
                    <SettingsRow
                        icon={MapPin}
                        title={t('shopping.default_shop')}
                        value={s.default_shop ?? t('shopping.none')}
                        onClick={() =>
                            setPick({
                                key: 'default_shop',
                                title: t('shopping.default_shop'),
                                value: s.default_shop ?? '',
                                options: [{ value: '', label: t('shopping.none') }, ...shops.map((n) => ({ value: n, label: n }))],
                            })
                        }
                    />
                    <SettingsRow
                        icon={ArrowDownUp}
                        title={t('shopping.sort_items')}
                        value={sortLabel[s.sort]}
                        onClick={() =>
                            setPick({
                                key: 'sort',
                                title: t('shopping.sort_items'),
                                value: s.sort,
                                options: Object.entries(sortLabel).map(([value, label]) => ({ value, label })),
                            })
                        }
                    />
                    <SettingsRow
                        icon={CircleCheck}
                        title={t('shopping.bought_items')}
                        value={boughtLabel[s.bought]}
                        onClick={() =>
                            setPick({
                                key: 'bought',
                                title: t('shopping.bought_items'),
                                value: s.bought,
                                options: Object.entries(boughtLabel).map(([value, label]) => ({ value, label })),
                            })
                        }
                    />
                </SettingsGroup>

                <SettingsGroup label={t('shopping.s_helpers')}>
                    {/* Only where the AI is set up: without it there is nothing to switch. */}
                    {aiEnabled && toggle(Sparkles, 'ai_sort', t('shopping.ai_sort'))}
                    {toggle(Copy, 'warn_duplicates', t('shopping.warn_duplicates'))}
                    {toggle(Sun, 'keep_awake', t('shopping.keep_awake'))}
                    <SettingsRow
                        icon={Mic}
                        title={t('shopping.voice_language')}
                        sub={t('shopping.voice_language_sub')}
                        value={voiceName}
                        onClick={() =>
                            setPick({
                                key: 'voice',
                                title: t('shopping.voice_language'),
                                value: voice,
                                options: VOICE_LANGS.map((l) => ({ value: l.code, label: l.label })),
                            })
                        }
                    />
                </SettingsGroup>

                <div className="flex flex-col gap-2">
                    <SettingsGroup label={t('shopping.s_cleanup')}>
                        <SettingsRow
                            icon={Sparkles}
                            title={t('shopping.auto_clean')}
                            sub={t('shopping.auto_clean_sub')}
                            right={
                                <SettingsSwitch
                                    on={s.auto_clean}
                                    onToggle={() => set({ auto_clean: !s.auto_clean })}
                                    label={t('shopping.auto_clean')}
                                />
                            }
                        />
                        {s.auto_clean && (
                            <SettingsRow
                                icon={Timer}
                                title={t('shopping.clean_after')}
                                value={days(s.clean_after_days)}
                                onClick={() =>
                                    setPick({
                                        key: 'clean_after_days',
                                        title: t('shopping.clean_after'),
                                        value: String(s.clean_after_days),
                                        options: [1, 3, 7, 14, 30].map((n) => ({ value: String(n), label: days(n) })),
                                    })
                                }
                            />
                        )}
                        <SettingsRow
                            icon={Trash2}
                            title={t('shopping.recently_deleted')}
                            value={t('shopping.kept_30_days')}
                            href="/settings/recently-deleted"
                        />
                    </SettingsGroup>
                    <p className="text-muted-foreground px-2 text-sm">
                        {s.auto_clean ? t('shopping.cleanup_note', { count: s.clean_after_days }) : t('shopping.cleanup_note_off')}
                    </p>
                </div>

                <SettingsGroup label={t('shopping.s_archive')}>
                    <SettingsRow
                        icon={Archive}
                        title={t('shopping.archived_lists')}
                        value={String(archived)}
                        onClick={() => router.visit('/m/shopping?archived=1')}
                    />
                </SettingsGroup>
            </SettingsPage>

            {/* One-tap picker: tap a choice, it saves and closes. */}
            <ResponsiveModal open={!!pick} onOpenChange={(o) => !o && setPick(null)} title={pick?.title ?? ''}>
                <div className="bg-surface-card divide-border flex flex-col divide-y overflow-hidden rounded-2xl border">
                    {pick?.options.map((o) => (
                        <button
                            key={o.value}
                            type="button"
                            onClick={() => choose(o.value)}
                            aria-pressed={o.value === pick.value}
                            className="text-primary flex min-h-12 items-center justify-between px-4 py-3 text-left text-base"
                        >
                            <span className={o.value === pick.value ? 'font-semibold' : ''}>{o.label}</span>
                            {o.value === pick.value && <CircleCheck className="text-primary size-5" />}
                        </button>
                    ))}
                </div>
            </ResponsiveModal>
        </AppLayout>
    );
}
