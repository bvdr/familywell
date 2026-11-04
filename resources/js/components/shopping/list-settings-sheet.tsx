import { router } from '@inertiajs/react';
import { Archive, ArrowDownUp, Check, CircleCheck, Copy, Lock, Store, Timer, Trash2, UserCheck, Users, WandSparkles } from 'lucide-react';
import { useState } from 'react';

import { SettingsRow } from '@/components/settings-ui';
import { ShopLogo } from '@/components/shopping/shop-logo';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';
import { LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/shopping';
import { cn } from '@/lib/utils';

export type ListPrefs = {
    id: number;
    name: string;
    store: string | null;
    color: string;
    icon: string;
    visibility: string;
    sort: 'aisle' | 'az' | 'added' | null; // null = the family's default
    bought: 'sink' | 'hide' | null;
    cleanAfterDays: number | null; // null = default, 0 = never
};
export type ListDefaults = { sort: 'aisle' | 'az' | 'added'; bought: 'sink' | 'hide'; cleanAfterDays: number }; // 0 = auto-clean off
type View = 'main' | 'icon' | 'shop' | 'sort' | 'bought' | 'clean';

const VISIBILITY = [
    { key: 'family', icon: Users, label: 'shopping.vis_family' },
    { key: 'invited', icon: UserCheck, label: 'shopping.vis_invited' },
    { key: 'private', icon: Lock, label: 'shopping.vis_only_me' },
] as const;
const DAYS = [1, 3, 7, 14, 30];

// List settings (design S2b), the ⋯ on a list: name, who can see it, then one-tap rows that push a picker (icon,
// default shop, sort, bought items, auto-clean S2c); duplicate / archive / delete at the bottom. Every change saves
// at once through `patch` (optimistic in the page); the name saves when the sheet closes.
export function ListSettingsSheet({
    open,
    onOpenChange,
    list,
    defaults,
    shops,
    boughtCount,
    patch,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    list: ListPrefs;
    defaults: ListDefaults;
    shops: { name: string; logo: string | null }[];
    boughtCount: number;
    patch: (data: Record<string, unknown>) => void;
}) {
    const t = useT();
    const [view, setView] = useState<View>('main');
    const [name, setName] = useState(list.name);
    const sortLabel = { aisle: t('shopping.sort_aisle'), az: t('shopping.sort_az'), added: t('shopping.sort_added') };
    const boughtLabel = { sink: t('shopping.bought_sink'), hide: t('shopping.bought_hide') };
    const daysLabel = (n: number) => (n ? t('shopping.n_days', { count: n }) : t('shopping.clean_off'));
    const clean = list.cleanAfterDays;

    const close = (v: boolean) => {
        if (v) {
            setName(list.name);
            setView('main');
        } else if (name.trim() && name.trim() !== list.name) patch({ name: name.trim() });
        onOpenChange(v);
    };
    // A picker row: tap picks, saves and goes back (S2c "One tap picks and goes back").
    const pick = (data: Record<string, unknown>) => {
        patch(data);
        setView('main');
    };
    const card = 'bg-surface-card divide-border flex flex-col divide-y overflow-hidden rounded-2xl border';

    const sub: Record<Exclude<View, 'main'>, { title: string; body: React.ReactNode }> = {
        icon: {
            title: t('shopping.color_icon'),
            body: (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap gap-2">
                        {Object.keys(LIST_COLORS).map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => patch({ color: c })}
                                aria-label={t('shopping.color_aria', { c })}
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-full',
                                    listColor(c),
                                    list.color === c && 'ring-primary ring-2 ring-offset-2',
                                )}
                            >
                                {list.color === c && <Check className="size-4" />}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {Object.keys(LIST_ICONS).map((ic) => {
                            const Icon = listIcon(ic);
                            return (
                                <button
                                    key={ic}
                                    type="button"
                                    onClick={() => patch({ icon: ic })}
                                    aria-label={t('shopping.icon_aria', { ic })}
                                    className={cn(
                                        'flex size-11 items-center justify-center rounded-xl border',
                                        list.icon === ic ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground',
                                    )}
                                >
                                    <Icon className="size-5" />
                                </button>
                            );
                        })}
                    </div>
                </div>
            ),
        },
        shop: {
            title: t('shopping.default_shop'),
            body: (
                <div className="flex flex-col gap-3">
                    <p className="text-muted-foreground text-sm">{t('shopping.list_shop_hint')}</p>
                    <div className={card}>
                        <Option on={!list.store} onClick={() => pick({ store: null })} title={t('shopping.none')} />
                        {shops.map((s) => (
                            <button
                                key={s.name}
                                type="button"
                                onClick={() => pick({ store: s.name })}
                                aria-pressed={list.store === s.name}
                                className={cn('flex min-h-14 items-center gap-3 px-4 py-2.5 text-left', list.store === s.name && 'bg-accent-subtle')}
                            >
                                <ShopLogo name={s.name} logo={s.logo} className="size-9 rounded-lg" />
                                <span className="text-primary min-w-0 flex-1 truncate text-base font-semibold">{s.name}</span>
                                {list.store === s.name && <CircleCheck className="text-primary size-5" />}
                            </button>
                        ))}
                    </div>
                </div>
            ),
        },
        sort: {
            title: t('shopping.sort_items'),
            body: (
                <div className={card}>
                    {(['aisle', 'az', 'added'] as const).map((v) => (
                        <Option key={v} on={(list.sort ?? defaults.sort) === v} onClick={() => pick({ sort: v })} title={sortLabel[v]} />
                    ))}
                </div>
            ),
        },
        bought: {
            title: t('shopping.bought_items'),
            body: (
                <div className={card}>
                    {(['sink', 'hide'] as const).map((v) => (
                        <Option key={v} on={(list.bought ?? defaults.bought) === v} onClick={() => pick({ bought: v })} title={boughtLabel[v]} />
                    ))}
                </div>
            ),
        },
        // S2c: Use default follows Shopping settings (so changing the default later still applies here); another
        // timing or Never overrides for this list only. The default's own timing isn't repeated as an option.
        clean: {
            title: t('shopping.auto_clean_short'),
            body: (
                <div className="flex flex-col gap-4">
                    <p className="text-primary text-base">{t('shopping.clean_lead')}</p>
                    <div className={card}>
                        <Option
                            on={clean === null}
                            onClick={() => pick({ clean_after_days: null })}
                            title={t('shopping.use_default')}
                            sub={t('shopping.from_settings', {
                                value: defaults.cleanAfterDays
                                    ? t('shopping.after_n_days', { count: defaults.cleanAfterDays })
                                    : t('shopping.clean_off'),
                            })}
                        />
                        {DAYS.filter((n) => n !== defaults.cleanAfterDays).map((n) => (
                            <Option
                                key={n}
                                on={clean === n}
                                onClick={() => pick({ clean_after_days: n })}
                                title={t('shopping.after_n_days', { count: n })}
                            />
                        ))}
                        <Option
                            on={clean === 0}
                            onClick={() => pick({ clean_after_days: 0 })}
                            title={t('shopping.never')}
                            sub={t('shopping.never_sub')}
                        />
                    </div>
                    <p className="bg-surface text-primary flex gap-3 rounded-2xl p-4 text-sm">
                        <Trash2 className="text-muted-foreground size-4 shrink-0" /> {t('shopping.clean_trash_note')}
                    </p>
                    {boughtCount > 0 && (
                        <button
                            type="button"
                            onClick={() => {
                                router.post(route('shopping.lists.clear-bought', list.id), {}, { preserveScroll: true });
                                close(false);
                            }}
                            className="border-border text-primary flex min-h-12 items-center justify-center gap-2 rounded-2xl border text-base font-semibold"
                        >
                            <WandSparkles className="size-4" /> {t('shopping.clear_bought_now', { count: boughtCount })}
                        </button>
                    )}
                </div>
            ),
        },
    };
    const current = view === 'main' ? null : sub[view];
    const IconNow = listIcon(list.icon);

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={close}
            title={current ? current.title : t('shopping.list_settings')}
            onBack={current ? () => setView('main') : undefined}
            backLabel={current ? list.name : undefined}
            footer={
                !current && (
                    <button
                        type="button"
                        onClick={() => close(false)}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold"
                    >
                        {t('common.done')}
                    </button>
                )
            }
        >
            {current ? (
                // The sub-view's own heading: the sheet header shows only the back pill there.
                <div className="flex flex-col gap-4">
                    <h3 className="font-display text-primary text-2xl font-bold">{current.title}</h3>
                    {current.body}
                </div>
            ) : (
                <div className="flex flex-col gap-5">
                    <label className="flex flex-col gap-2">
                        <span className="text-primary text-sm font-semibold">{t('shopping.name_label')}</span>
                        <Input value={name} onChange={(e) => setName(e.target.value)} autoComplete="off" className="h-12 text-base" />
                    </label>
                    <div className="flex flex-col gap-2">
                        <span className="text-primary text-sm font-semibold">{t('shopping.who_can_see')}</span>
                        <div role="radiogroup" className="bg-muted flex rounded-full p-1">
                            {VISIBILITY.map((v) => (
                                <button
                                    key={v.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={list.visibility === v.key}
                                    onClick={() => patch({ visibility: v.key })}
                                    className={cn(
                                        'flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-full text-sm',
                                        list.visibility === v.key ? 'bg-surface-card text-primary font-semibold shadow-sm' : 'text-muted-foreground',
                                    )}
                                >
                                    <v.icon className="size-4" /> {t(v.label)}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className={card}>
                        <SettingsRow icon={IconNow} title={t('shopping.icon')} value={t('shopping.change')} onClick={() => setView('icon')} />
                        <SettingsRow
                            icon={Store}
                            title={t('shopping.default_shop')}
                            value={list.store ?? t('shopping.none')}
                            onClick={() => setView('shop')}
                        />
                        <SettingsRow
                            icon={ArrowDownUp}
                            title={t('shopping.sort_items')}
                            value={sortLabel[list.sort ?? defaults.sort]}
                            onClick={() => setView('sort')}
                        />
                        <SettingsRow
                            icon={CircleCheck}
                            title={t('shopping.bought_items')}
                            value={boughtLabel[list.bought ?? defaults.bought]}
                            onClick={() => setView('bought')}
                        />
                        <SettingsRow
                            icon={Timer}
                            title={t('shopping.auto_clean_short')}
                            value={
                                clean === null
                                    ? t('shopping.default_value', { value: daysLabel(defaults.cleanAfterDays) })
                                    : clean === 0
                                      ? t('shopping.never')
                                      : daysLabel(clean)
                            }
                            onClick={() => setView('clean')}
                        />
                    </div>
                    <div className="flex items-center gap-5">
                        <button
                            type="button"
                            onClick={() => router.post(route('shopping.lists.duplicate', list.id))}
                            className="text-primary flex min-h-11 items-center gap-1.5 text-sm font-semibold"
                        >
                            <Copy className="size-4" /> {t('shopping.duplicate')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                patch({ archived: true });
                                close(false);
                            }}
                            className="text-primary flex min-h-11 items-center gap-1.5 text-sm font-semibold"
                        >
                            <Archive className="size-4" /> {t('shopping.archive')}
                        </button>
                        <button
                            type="button"
                            onClick={() => confirm(t('shopping.confirm_delete_list')) && router.delete(route('shopping.lists.destroy', list.id))}
                            className="text-destructive ml-auto flex min-h-11 items-center gap-1.5 text-sm font-semibold"
                        >
                            <Trash2 className="size-4" /> {t('common.delete')}
                        </button>
                    </div>
                </div>
            )}
        </ResponsiveModal>
    );
}

// One choice in a picker: title, optional line under it, and a radio dot on the right (S2c).
function Option({ on, onClick, title, sub }: { on: boolean; onClick: () => void; title: string; sub?: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={on}
            className={cn('flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left', on && 'bg-accent-subtle')}
        >
            <span className="min-w-0 flex-1">
                <span className="text-primary block text-base font-semibold">{title}</span>
                {sub && <span className="text-muted-foreground block text-sm">{sub}</span>}
            </span>
            <span
                className={cn(
                    'flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                    on ? 'border-primary bg-primary' : 'border-border',
                )}
            >
                {on && <span className="bg-accent-strong size-2.5 rounded-full" />}
            </span>
        </button>
    );
}
