import { ShopLogo } from '@/components/shopping/shop-logo';
import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, ChevronRight, Lock, Plus, RotateCcw, Settings, ShoppingCart, Store, UserCheck, Users } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { appLocale, useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/shopping';
import { type BreadcrumbItem } from '@/types';

// Visibility option copy keys, resolved via t() in the component (icons stay static here).
const VISIBILITY = [
    { key: 'family', icon: Users, titleKey: 'shopping.visibility_family_title', subKey: 'shopping.visibility_family_sub' },
    { key: 'invited', icon: UserCheck, titleKey: 'shopping.visibility_invited_title', subKey: 'shopping.visibility_invited_sub' },
    { key: 'private', icon: Lock, titleKey: 'shopping.visibility_private_title', subKey: 'shopping.visibility_private_sub' },
] as const;

type Member = { id: number; name: string };
type List = {
    id: number;
    name: string;
    store: string | null;
    color: string;
    icon: string;
    itemCount: number;
    boughtCount: number;
    archived: boolean;
    visibility: string;
    members: Member[];
};

// One list in the Lists card (S1): its tile, name (a lock when only you see it), shop and progress, and how many
// are left to buy on the right.
function ListRow({ list }: { list: List }) {
    const t = useT();
    const Icon = listIcon(list.icon);
    const left = list.itemCount - list.boughtCount;
    const sub = [
        list.store,
        list.visibility === 'private' ? t('shopping.only_you_see') : t('shopping.n_of_m_bought', { bought: list.boughtCount, total: list.itemCount }),
    ]
        .filter(Boolean)
        .join(' · ');
    return (
        <Link href={route('shopping.show', list.id)} prefetch className="hover:bg-muted/40 flex items-center gap-3 px-4 py-3.5 transition-colors">
            <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                <Icon className="size-5.5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="text-primary flex items-center gap-1.5 text-[17px] font-semibold">
                    <span className="truncate">{list.name}</span>
                    {list.visibility === 'private' && (
                        <Lock className="text-muted-foreground size-3.5 shrink-0" aria-label={t('shopping.only_you_see')} />
                    )}
                </span>
                <span className="text-muted-foreground block truncate text-sm">{sub}</span>
            </span>
            {left > 0 && <span className="text-primary shrink-0 text-base font-bold tabular-nums">{left}</span>}
            <ChevronRight className="text-muted-foreground size-5 shrink-0" />
        </Link>
    );
}

type Shop = {
    id: number;
    name: string;
    icon: string;
    color: string;
    toBuy: number;
    logo?: string | null;
    lists?: string[];
    lastTrip?: string | null;
};

// One shop in the Shops tab (S1b): its logo, the lists waiting on it (or its last trip when there's nothing to buy),
// and how many things to buy there.
function ShopRow({ shop: s, href }: { shop: Shop; href?: string }) {
    const t = useT();
    const sub = s.toBuy > 0 ? (s.lists ?? []).join(', ') : s.lastTrip ? t('shopping.last_trip', { day: shortDay(s.lastTrip) }) : '';
    return (
        <Link href={href ?? `/m/shopping/shops/${s.id}`} prefetch className="hover:bg-muted/40 flex items-center gap-3 px-4 py-3.5 transition-colors">
            <ShopLogo name={s.name} logo={s.logo} className="size-11 rounded-xl" />
            <span className="min-w-0 flex-1">
                <span className="text-primary block truncate text-[17px] font-semibold">{s.name}</span>
                {sub && <span className="text-muted-foreground block truncate text-sm">{sub}</span>}
            </span>
            <span className={`shrink-0 text-base tabular-nums ${s.toBuy > 0 ? 'text-primary font-bold' : 'text-muted-foreground font-mono'}`}>
                {s.toBuy}
            </span>
            <ChevronRight className="text-muted-foreground size-5 shrink-0" />
        </Link>
    );
}

const shortDay = (d: string) => new Date(`${d}T12:00`).toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
const eyebrow = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase';

export default function Lists({
    lists,
    shops = [],
    noShopCount = 0,
    initialView = 'lists',
    defaultShop = null,
}: {
    lists: List[];
    shops?: Shop[];
    noShopCount?: number;
    initialView?: 'lists' | 'shops';
    defaultShop?: string | null;
}) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.shopping'), href: '/m/shopping' }];
    // Lists / Shops is a client-side tab (no navigation) so the header, gear and tab bar are identical
    // across both and switching is instant. The route just picks which tab opens first.
    const [view, setView] = useState<'lists' | 'shops'>(initialView);
    const [creating, setCreating] = useState(openNewOnArrival);
    const [creatingShop, setCreatingShop] = useState(false);
    // Opened from Shopping settings > Archived lists (?archived=1).
    const [showArchived, setShowArchived] = useState(
        () => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('archived'),
    );
    const [shopName, setShopName] = useState('');
    // Focus the name field when each create sheet opens (the drawer overrides plain autoFocus).
    const listNameRef = useRef<HTMLInputElement>(null);
    const shopNameRef = useRef<HTMLInputElement>(null);
    // The "+" is tab-aware: a new list on Lists, a new shop on Shops.
    const startCreate = () => (view === 'shops' ? setCreatingShop(true) : setCreating(true));
    useAddAction(startCreate);
    const submitShop = () => {
        if (!shopName.trim()) return;
        router.post(
            route('shopping.shops.store'),
            { name: shopName.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShopName('');
                    setCreatingShop(false);
                },
            },
        );
    };
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        name: '',
        store: defaultShop ?? '', // new lists start with the family's default shop (Shopping settings)
        color: 'green',
        icon: 'cart',
        visibility: 'family',
    });
    const [extraStores, setExtraStores] = useState<string[]>([]); // shops typed via the picker this session

    const archivedOpt = useOptimistic<boolean>();
    const isArchived = (l: List) => archivedOpt.get(l.id, l.archived);
    const active = lists.filter((l) => !isArchived(l));
    const archived = lists.filter((l) => isArchived(l));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, store: d.store || null })); // empty shop => no list-level shop
        post(route('shopping.lists.store'), {
            onSuccess: () => {
                reset();
                setExtraStores([]);
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.shopping')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-[34px] leading-tight font-bold tracking-tight">{t('nav.shopping')}</h1>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('shopping.settings')}
                            aria-label={t('shopping.shopping_settings')}
                            className="border-border text-primary hover:bg-surface flex size-10 items-center justify-center rounded-full border transition-colors"
                        >
                            <Settings className="size-5" />
                        </Link>
                        {/* Hidden on mobile: the nav's context-aware "+" creates here. Desktop has no nav "+". */}
                        <Button
                            size="icon"
                            className="hidden rounded-full md:inline-flex"
                            onClick={startCreate}
                            aria-label={view === 'shops' ? t('shopping.new_shop') : t('shopping.new_list')}
                        >
                            <Plus className="size-5" />
                        </Button>
                    </div>
                </div>

                {/* Client-side Lists / Shops tab — no page load, same bar in both. */}
                <div className="border-border bg-surface flex rounded-full border p-1">
                    {(['lists', 'shops'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => setView(v)}
                            className={`flex-1 rounded-full px-4 py-1.5 text-sm font-medium capitalize transition-colors ${
                                view === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'
                            }`}
                        >
                            {t(v === 'lists' ? 'shopping.tab_lists' : 'shopping.tab_shops')}
                        </button>
                    ))}
                </div>

                <ResponsiveModal
                    open={creating}
                    onOpenChange={setCreating}
                    title={t('shopping.new_list')}
                    autoFocusRef={listNameRef}
                    footer={
                        <Button type="submit" form="new-list" disabled={processing || !data.name.trim()} className="w-full">
                            <Plus className="size-4" /> {t('shopping.create_list')}
                        </Button>
                    }
                >
                    <form id="new-list" onSubmit={submit} className="flex flex-col gap-5">
                        <div className="flex items-center gap-3">
                            <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(data.color)}`}>
                                {(() => {
                                    const Icon = listIcon(data.icon);
                                    return <Icon className="size-6" />;
                                })()}
                            </span>
                            <Input
                                ref={listNameRef}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder={t('shopping.list_name')}
                                className="text-base"
                            />
                        </div>
                        <InputError message={errors.name} />

                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                {t('shopping.color_icon')}
                            </Label>
                            <div className="flex flex-wrap gap-2">
                                {Object.keys(LIST_COLORS).map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        onClick={() => setData('color', c)}
                                        aria-label={t('shopping.color_aria', { c })}
                                        className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${data.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                                    >
                                        {data.color === c && <Check className="size-4" />}
                                    </button>
                                ))}
                            </div>
                            <div className="mt-1 flex flex-wrap gap-2">
                                {Object.keys(LIST_ICONS).map((ic) => {
                                    const Icon = listIcon(ic);
                                    return (
                                        <button
                                            key={ic}
                                            type="button"
                                            onClick={() => setData('icon', ic)}
                                            aria-label={t('shopping.icon_aria', { ic })}
                                            className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                                                data.icon === ic
                                                    ? 'border-primary bg-accent-subtle text-primary'
                                                    : 'border-border text-muted-foreground'
                                            }`}
                                        >
                                            <Icon className="size-4.5" />
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Optional list-level shop: items without their own shop inherit it. Leave empty
                            for a list that spans shops (each item carries its own). */}
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                    {t('shopping.list_shop')}
                                </Label>
                                {data.store && (
                                    <button
                                        type="button"
                                        onClick={() => setData('store', '')}
                                        className="text-muted-foreground hover:text-primary text-xs"
                                    >
                                        {t('shopping.clear')}
                                    </button>
                                )}
                            </div>
                            <Picker
                                searchable
                                placeholder={t('shopping.pick_shop')}
                                options={[...new Set([...shops.map((s) => s.name), ...extraStores, data.store].filter(Boolean))].map((s) => ({
                                    value: s,
                                    label: s,
                                    icon: Store,
                                }))}
                                selected={data.store ? [data.store] : []}
                                onChange={(next) => setData('store', next[0] ?? '')}
                                onCreate={(name) => {
                                    setExtraStores((prev) => [...new Set([...prev, name])]);
                                    setData('store', name);
                                }}
                            />
                            <p className="text-muted-foreground text-xs">{t('shopping.list_shop_hint')}</p>
                        </div>

                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                {t('shopping.who_can_view')}
                            </Label>
                            <div className="flex flex-col gap-2">
                                {VISIBILITY.map((v) => {
                                    const active = data.visibility === v.key;
                                    return (
                                        <button
                                            key={v.key}
                                            type="button"
                                            onClick={() => setData('visibility', v.key)}
                                            className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${
                                                active ? 'border-primary bg-accent-subtle' : 'border-border'
                                            }`}
                                        >
                                            <v.icon className="text-primary size-5 shrink-0" />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-primary text-sm font-medium">{t(v.titleKey)}</div>
                                                <div className="text-muted-foreground text-xs">{t(v.subKey)}</div>
                                            </div>
                                            {active && <Check className="text-primary size-4 shrink-0" />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </form>
                </ResponsiveModal>

                <ResponsiveModal
                    open={creatingShop}
                    onOpenChange={setCreatingShop}
                    title={t('shopping.new_shop')}
                    autoFocusRef={shopNameRef}
                    footer={
                        <Button disabled={!shopName.trim()} onClick={submitShop} className="w-full">
                            <Plus className="size-4" /> {t('shopping.add_shop')}
                        </Button>
                    }
                >
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.shop_name')}</Label>
                        <Input
                            ref={shopNameRef}
                            value={shopName}
                            onChange={(e) => setShopName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && submitShop()}
                            placeholder={t('shopping.shop_name_placeholder')}
                            className="text-base"
                        />
                    </div>
                </ResponsiveModal>

                {view === 'lists' && (
                    <>
                        {active.length === 0 && !creating && (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                                <ShoppingCart className="size-6" />
                                <p className="text-sm">{t('shopping.no_lists_yet')}</p>
                            </div>
                        )}

                        {active.length > 0 && (
                            <div className="bg-surface-card divide-border divide-y overflow-hidden rounded-[22px] border">
                                {active.map((l) => (
                                    <ListRow key={l.id} list={l} />
                                ))}
                            </div>
                        )}

                        {/* Archived lists sit below, folded into one quiet line (Shopping settings > Archived lists opens it). */}
                        {archived.length > 0 && (
                            <button
                                type="button"
                                onClick={() => setShowArchived((v) => !v)}
                                aria-expanded={showArchived}
                                className="text-muted-foreground mx-auto py-2 text-sm font-semibold"
                            >
                                {t('shopping.n_archived', { count: archived.length })}
                            </button>
                        )}
                        {showArchived && archived.length > 0 && (
                            <div className="bg-surface-card divide-border divide-y overflow-hidden rounded-[22px] border">
                                {archived.map((l) => {
                                    const Icon = listIcon(l.icon);
                                    return (
                                        <div key={l.id} className="flex items-center gap-3 px-4 py-3 opacity-70">
                                            <span className="text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg border">
                                                <Icon className="size-4.5" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="text-primary truncate text-sm font-medium">{l.name}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {t('shopping.items_count', { bought: l.boughtCount, total: l.itemCount })}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    archivedOpt.set(l.id, false);
                                                    void save('patch', route('shopping.lists.update', l.id), { archived: false }).then(() =>
                                                        archivedOpt.clear(l.id),
                                                    );
                                                }}
                                                aria-label={t('shopping.restore_list')}
                                                className="text-muted-foreground hover:text-primary flex size-8 items-center justify-center rounded-lg transition-colors"
                                            >
                                                <RotateCcw className="size-4" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                )}

                {/* Shops (S1b): those with something waiting first, with the lists that need them; the rest below, so they
                    can still be opened or edited; Add a shop at the end. */}
                {view === 'shops' &&
                    (() => {
                        const waiting = shops.filter((s) => s.toBuy > 0);
                        const quiet = shops.filter((s) => s.toBuy === 0);
                        const toBuy = waiting.length + (noShopCount > 0 ? 1 : 0);
                        return (
                            <div className="flex flex-col gap-5">
                                {toBuy > 0 && (
                                    <section className="flex flex-col gap-2">
                                        <h2 className={eyebrow}>{t('shopping.to_buy_heading', { count: toBuy })}</h2>
                                        <div className="bg-surface-card divide-border divide-y overflow-hidden rounded-[22px] border">
                                            {waiting.map((s) => (
                                                <ShopRow key={s.id} shop={s} />
                                            ))}
                                            {noShopCount > 0 && (
                                                <ShopRow
                                                    shop={{ id: 0, name: t('shopping.no_shop'), icon: '', color: '', toBuy: noShopCount }}
                                                    href="/m/shopping/shops/none"
                                                />
                                            )}
                                        </div>
                                    </section>
                                )}
                                <section className="flex flex-col gap-2">
                                    {quiet.length > 0 && <h2 className={eyebrow}>{t('shopping.nothing_to_buy_count', { count: quiet.length })}</h2>}
                                    <div className="bg-surface-card divide-border divide-y overflow-hidden rounded-[22px] border">
                                        {quiet.map((s) => (
                                            <ShopRow key={s.id} shop={s} />
                                        ))}
                                        <button
                                            type="button"
                                            onClick={() => setCreatingShop(true)}
                                            className="flex w-full items-center gap-3 px-4 py-3.5 text-left"
                                        >
                                            <span className="border-border text-primary flex size-11 shrink-0 items-center justify-center rounded-xl border bg-white">
                                                <Plus className="size-5" />
                                            </span>
                                            <span className="text-primary text-[17px] font-semibold">{t('shopping.add_a_shop')}</span>
                                        </button>
                                    </div>
                                </section>
                            </div>
                        );
                    })()}
            </div>
        </AppLayout>
    );
}
