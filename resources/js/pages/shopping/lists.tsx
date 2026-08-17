import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, Check, ChevronRight, Lock, Plus, RotateCcw, Settings, ShoppingCart, Store, UserCheck, Users } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
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

// Deterministic member badge, colour by first letter (matches the M/D/E chips in the design).
function Avatars({ members }: { members: Member[] }) {
    if (members.length === 0) return null;
    return (
        <div className="flex -space-x-1.5">
            {members.slice(0, 3).map((m) => (
                <MemberAvatar key={m.id} userId={m.id} name={m.name} ring className="size-6 text-[10px]" />
            ))}
            {members.length > 3 && <span className="text-muted-foreground self-center pl-2.5 text-xs">+{members.length - 3}</span>}
        </div>
    );
}

function ListCard({ list }: { list: List }) {
    const t = useT();
    const Icon = listIcon(list.icon);
    return (
        <Link
            href={route('shopping.show', list.id)}
            prefetch
            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-4 text-left transition-colors"
        >
            <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                <Icon className="size-5.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-primary flex items-center gap-1.5 font-medium">
                    <span className="truncate">{list.name}</span>
                    {list.visibility === 'private' && <Lock className="text-muted-foreground size-3.5 shrink-0" />}
                </div>
                <div className="text-muted-foreground mt-0.5 text-xs">
                    {t('shopping.items_count', { bought: list.boughtCount, total: list.itemCount })}
                </div>
            </div>
            <Avatars members={list.members} />
            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
        </Link>
    );
}

type Shop = { id: number; name: string; icon: string; color: string; toBuy: number };

function ShopCard({ shop: s, faded = false, href }: { shop: Shop; faded?: boolean; href?: string }) {
    const t = useT();
    return (
        <Link
            key={s.id}
            href={href ?? `/m/shopping/shops/${s.id}`}
            prefetch
            className={`bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-4 text-left transition-colors ${faded ? 'opacity-55' : ''}`}
        >
            <span className="bg-accent-subtle text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                <Store className="size-5.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-primary truncate font-medium">{s.name}</div>
                <div className="text-muted-foreground mt-0.5 text-xs">
                    {s.toBuy > 0 ? t('shopping.to_buy', { n: s.toBuy }) : t('shopping.nothing_to_buy')}
                </div>
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
        </Link>
    );
}

export default function Lists({
    lists,
    shops = [],
    noShopCount = 0,
    initialView = 'lists',
}: {
    lists: List[];
    shops?: Shop[];
    noShopCount?: number;
    initialView?: 'lists' | 'shops';
}) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.shopping'), href: '/m/shopping' }];
    // Lists / Shops is a client-side tab (no navigation) so the header, gear and tab bar are identical
    // across both and switching is instant. The route just picks which tab opens first.
    const [view, setView] = useState<'lists' | 'shops'>(initialView);
    const [creating, setCreating] = useState(openNewOnArrival);
    const [creatingShop, setCreatingShop] = useState(false);
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
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        color: 'green',
        icon: 'cart',
        visibility: 'family',
    });

    const archivedOpt = useOptimistic<boolean>();
    const isArchived = (l: List) => archivedOpt.get(l.id, l.archived);
    const active = lists.filter((l) => !isArchived(l));
    const archived = lists.filter((l) => isArchived(l));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('shopping.lists.store'), {
            onSuccess: () => {
                reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.shopping')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-2xl">{t('shopping.grocery_lists')}</h1>
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
                                view === v ? 'bg-primary text-primary-foreground' : 'text-primary'
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

                        {/* No list-level store: items each carry their own shop, so a list can span shops. */}

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

                        <div className="flex flex-col gap-3">
                            {active.map((l) => (
                                <ListCard key={l.id} list={l} />
                            ))}
                        </div>

                        {archived.length > 0 && (
                            <div className="mt-2 flex flex-col gap-2">
                                <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                    <Archive className="size-3.5" /> {t('shopping.archived')}
                                </h2>
                                {archived.map((l) => {
                                    const Icon = listIcon(l.icon);
                                    return (
                                        <div key={l.id} className="bg-surface flex items-center gap-3 rounded-xl border p-3 opacity-70">
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
                                                    router.patch(
                                                        route('shopping.lists.update', l.id),
                                                        { archived: false },
                                                        {
                                                            preserveScroll: true,
                                                            preserveState: true,
                                                            onSuccess: () => archivedOpt.clear(l.id),
                                                            onError: () => archivedOpt.clear(l.id),
                                                        },
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

                {view === 'shops' &&
                    (shops.length === 0 && noShopCount === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                            <Store className="size-6" />
                            <p className="text-sm">{t('shopping.no_shops_yet')}</p>
                        </div>
                    ) : (
                        // Shops with something to buy come first; the rest ("nothing to buy") are
                        // faded and grouped below so the active stores are what you scan first. The
                        // "No shop" catch-all sits with the active stores when it has items.
                        (() => {
                            const active = shops.filter((s) => s.toBuy > 0);
                            const empty = shops.filter((s) => s.toBuy === 0);
                            return (
                                <div className="flex flex-col gap-3">
                                    {active.map((s) => (
                                        <ShopCard key={s.id} shop={s} />
                                    ))}
                                    {noShopCount > 0 && (
                                        <ShopCard
                                            shop={{ id: 0, name: t('shopping.no_shop'), icon: '', color: '', toBuy: noShopCount }}
                                            href="/m/shopping/shops/none"
                                        />
                                    )}
                                    {empty.length > 0 && (active.length > 0 || noShopCount > 0) && (
                                        <div className="text-muted-foreground mt-1 px-1 text-xs font-semibold tracking-widest uppercase">
                                            {t('shopping.nothing_to_buy_heading')}
                                        </div>
                                    )}
                                    {empty.map((s) => (
                                        <ShopCard key={s.id} shop={s} faded />
                                    ))}
                                </div>
                            );
                        })()
                    ))}
            </div>
        </AppLayout>
    );
}
