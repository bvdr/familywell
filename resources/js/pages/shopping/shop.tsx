import { Head, Link, router } from '@inertiajs/react';
import { Calendar, Check, ChevronLeft, Minus, Plus, Settings, ShoppingCart, Store, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { AisleEditor } from '@/components/aisle-editor';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { useT } from '@/lib/i18n';
import { aisleIcon } from '@/lib/shopping';
import { type BreadcrumbItem } from '@/types';

type Aisle = { slug: string; name: string; icon: string };
type Item = {
    id: number;
    name: string;
    quantity: string | null;
    department: string;
    bought: boolean;
    note: string | null;
    dueDate: string | null;
    store: string | null;
    altStores: string[];
    listId: number;
    listName: string;
};
type Shop = { id: number; name: string; icon: string; color: string };
type ListRef = { id: number; name: string };
type Draft = { name: string; quantity: string; department: string; note: string; due_date: string; shops: string[]; list_id: number | null };

export default function ShopDetail({
    shop: serverShop,
    aisles,
    items: serverItems,
    shops = [],
    lists = [],
    defaultListId = null,
    virtual = false,
}: {
    shop: Shop;
    aisles: Aisle[];
    items: Item[];
    shops?: string[];
    lists?: ListRef[];
    defaultListId?: number | null;
    // The "No shop" bucket reuses this page in `virtual` mode: no real shop entity, so rename, aisle
    // editing and add-at-shop are hidden and items render as one flat list. The title is localised here.
    virtual?: boolean;
}) {
    const t = useT();
    // Optimistic rename: shadow the server shop with the pending name so the title/breadcrumb flip on
    // Enter, not after the round trip. Cleared on settle; the reloaded prop is then authoritative.
    const nameOpt = useOptimistic<string>();
    const shop: Shop = { ...serverShop, name: nameOpt.get(serverShop.id, serverShop.name) };
    // Optimistic removal: a deleted item disappears immediately, reappearing only if the delete fails.
    const removedOpt = useOptimistic<boolean>();
    const items = serverItems.filter((i) => !removedOpt.get(i.id, false));
    const shopTitle = virtual ? t('shopping.no_shop') : shop.name;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.shopping'), href: '/m/shopping' },
        { title: t('shopping.tab_shops'), href: '/m/shopping/shops' },
        { title: shopTitle, href: virtual ? '/m/shopping/shops/none' : `/m/shopping/shops/${shop.id}` },
    ];

    const boughtOpt = useOptimistic<boolean>();
    const isBought = (i: Item) => boughtOpt.get(i.id, i.bought);
    const toggle = (i: Item) => {
        boughtOpt.set(i.id, !isBought(i));
        // Empty-body PATCH flips `bought` on the item in its own list.
        router.patch(
            route('shopping.items.update', i.id),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => boughtOpt.clear(i.id), onError: () => boughtOpt.clear(i.id) },
        );
    };

    // Item editor: null = closed, 0 = new item at this shop, >0 = editing that id.
    const [editingId, setEditingId] = useState<number | null>(null);
    const emptyDraft = (): Draft => ({
        name: '',
        quantity: '',
        department: aisles[0]?.slug ?? 'other',
        note: '',
        due_date: '',
        shops: virtual ? [] : [shop.name],
        list_id: defaultListId,
    });
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const skipAutosave = useRef(true);
    const openNew = () => {
        skipAutosave.current = true;
        setDraft(emptyDraft());
        setEditingId(0);
    };
    const openEdit = (i: Item) => {
        skipAutosave.current = true;
        const shopsSel = [...new Set([i.store, ...i.altStores].filter(Boolean) as string[])];
        setDraft({
            name: i.name,
            quantity: i.quantity ?? '',
            department: i.department,
            note: i.note ?? '',
            due_date: i.dueDate ?? '',
            // On the "No shop" page there's no shop to pre-select; leave it empty so picking one moves
            // the item out of this bucket.
            shops: shopsSel.length ? shopsSel : virtual ? [] : [shop.name],
            list_id: i.listId,
        });
        setEditingId(i.id);
    };
    useAddAction(openNew); // the nav "+" adds an item at this shop

    const buildPayload = (d: Draft) => {
        const shopsUniq = [...new Set(d.shops)];
        return {
            name: d.name.trim(),
            quantity: d.quantity || null,
            department: d.department,
            note: d.note || null,
            due_date: d.due_date || null,
            // Virtual bucket has no shop to default to: keep store null unless the user picks one.
            store: shopsUniq[0] ?? (virtual ? null : shop.name),
            alt_stores: shopsUniq.slice(1),
        };
    };
    const patchItem = (id: number) =>
        router.patch(route('shopping.items.update', id), buildPayload(draft), { preserveScroll: true, preserveState: true });
    // `adding` guards a double-tap from creating duplicate items.
    const [adding, setAdding] = useState(false);
    const addItem = () => {
        if (!draft.name.trim() || adding) return;
        setAdding(true);
        const opts = { preserveScroll: true, onSuccess: () => setEditingId(null), onFinish: () => setAdding(false) };
        if (virtual) {
            // No shop to add "at": add straight to the chosen list (store stays whatever the picker set).
            if (!draft.list_id) return void setAdding(false);
            router.post(route('shopping.items.store', draft.list_id), buildPayload(draft), opts);
        } else {
            router.post(route('shopping.shops.items.store', shop.id), { ...buildPayload(draft), list_id: draft.list_id }, opts);
        }
    };
    // Editing auto-saves (debounced); new items commit via the Add button.
    useEffect(() => {
        if (!editingId) return;
        if (skipAutosave.current) {
            skipAutosave.current = false;
            return;
        }
        if (!draft.name.trim()) return;
        const t = setTimeout(() => patchItem(editingId), 500);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft, editingId]);
    const deleteItem = () => {
        const id = editingId;
        if (!id) return;
        setEditingId(null);
        removedOpt.set(id, true);
        router.delete(route('shopping.items.destroy', id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removedOpt.clear(id),
            onError: () => removedOpt.clear(id),
        });
    };
    const bump = (delta: number) =>
        setDraft((d) => {
            const n = parseInt(d.quantity, 10);
            return { ...d, quantity: String(Math.max(0, (isNaN(n) ? 0 : n) + delta)) };
        });

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [aislesOpen, setAislesOpen] = useState(false);
    const [name, setName] = useState(shop.name);
    // Focus (shopping) mode: full-screen run through this shop's items. Auto-opens when arriving via a
    // store header's ?focus=1 deep link.
    const [focus, setFocus] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('focus') === '1');
    const saveName = () => {
        const n = name.trim();
        if (!n || n === shop.name) return;
        nameOpt.set(shop.id, n);
        router.patch(
            route('shopping.shops.update', shop.id),
            { name: n },
            { preserveScroll: true, preserveState: true, onSuccess: () => nameOpt.clear(shop.id), onError: () => nameOpt.clear(shop.id) },
        );
    };

    // Split into "to buy" (grouped by the shop's aisles, walk order) and a bought pile at the bottom.
    // Computed each render (not memoised) so an optimistic check moves the item immediately.
    const toBuy = items.filter((i) => !isBought(i));
    const done = items.filter((i) => isBought(i));
    const bySlug = new Map(aisles.map((a) => [a.slug, a]));
    const otherSlug = bySlug.has('other') ? 'other' : aisles[aisles.length - 1]?.slug;
    // No shop = no aisles: render one headerless group. Otherwise group by the shop's aisles in walk order.
    const groups: { aisle: Aisle | null; items: Item[] }[] = virtual
        ? toBuy.length
            ? [{ aisle: null, items: toBuy }]
            : []
        : aisles
              .map((a) => ({
                  aisle: a as Aisle | null,
                  items: toBuy.filter((i) => (bySlug.has(i.department) ? i.department : otherSlug) === a.slug),
              }))
              .filter((g) => g.items.length > 0);
    const remaining = toBuy.length;

    const departmentOptions = aisles.map((a) => ({ value: a.slug, label: a.name, icon: aisleIcon(a.icon) }));
    const shopOptions = [...new Set([...shops, ...draft.shops].filter(Boolean))].map((s) => ({ value: s, label: s, icon: Store }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={shopTitle} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Link
                        href="/m/shopping/shops"
                        aria-label={t('shopping.back_to_shops')}
                        className="text-muted-foreground hover:text-primary -ml-1 flex size-8 items-center justify-center"
                    >
                        <ChevronLeft className="size-5" />
                    </Link>
                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-xl">
                        <Store className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary truncate text-2xl">{shopTitle}</h1>
                        <p className="text-muted-foreground text-sm">{t('shopping.to_buy', { n: remaining })}</p>
                    </div>
                    {/* No shop entity to configure on the virtual bucket. */}
                    {!virtual && (
                        <button
                            type="button"
                            onClick={() => setSettingsOpen(true)}
                            aria-label={t('shopping.shop_settings')}
                            className="border-border text-primary hover:bg-surface flex size-10 items-center justify-center rounded-full border"
                        >
                            <Settings className="size-5" />
                        </button>
                    )}
                </div>

                {items.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        {virtual ? t('shopping.no_shop_empty') : t('shopping.nothing_needed_at', { store: shop.name })}
                    </div>
                )}

                {groups.map((g) => {
                    const Icon = g.aisle ? aisleIcon(g.aisle.icon) : null;
                    return (
                        <div key={g.aisle?.slug ?? 'all'} className="flex flex-col gap-2">
                            {g.aisle && Icon && (
                                <div className="text-muted-foreground flex items-center gap-1.5 px-1 text-xs font-semibold tracking-widest uppercase">
                                    <Icon className="size-4" /> {g.aisle.name}
                                </div>
                            )}
                            {g.items.map((i) => (
                                <div key={i.id} className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-2.5">
                                    <button
                                        type="button"
                                        onClick={() => toggle(i)}
                                        aria-label={t('shopping.mark_bought')}
                                        className="border-border flex size-6 shrink-0 items-center justify-center rounded-full border"
                                    />
                                    {/* Tap the body to edit the item. */}
                                    <button type="button" onClick={() => openEdit(i)} className="min-w-0 flex-1 text-left">
                                        <span className="text-primary block truncate text-[15px]">{i.name}</span>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {i.quantity ? `${i.quantity} · ` : ''}
                                            {t('shopping.from_list', { list: i.listName })}
                                        </span>
                                    </button>
                                </div>
                            ))}
                        </div>
                    );
                })}

                {done.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <div className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{t('shopping.in_cart')}</div>
                        {done.map((i) => (
                            <button
                                key={i.id}
                                type="button"
                                onClick={() => toggle(i)}
                                className="bg-surface flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left opacity-70"
                            >
                                <span className="border-primary bg-accent-strong text-accent-strong-foreground flex size-6 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-3.5" />
                                </span>
                                <span className="text-muted-foreground min-w-0 flex-1 truncate text-[15px] line-through">{i.name}</span>
                            </button>
                        ))}
                    </div>
                )}

                {/* Add an item at this shop (goes to your default list unless you pick another). */}
                <Button type="button" variant="outline" onClick={openNew} className="mt-1">
                    <Plus className="size-4" /> {t('shopping.add_item')}
                </Button>
                {remaining > 0 && (
                    <Button type="button" onClick={() => setFocus(true)}>
                        <ShoppingCart className="size-4" /> {t('shopping.start_shopping')}
                    </Button>
                )}
            </div>

            {/* Focus (shopping) mode: full-screen run through this shop's unbought items. */}
            {focus &&
                createPortal(
                    <div className="bg-primary text-primary-foreground fixed inset-0 z-[60] flex flex-col">
                        <div className="px-5 pt-[calc(1rem+env(safe-area-inset-top))] pb-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-accent-strong-foreground/60 text-[11px] font-semibold tracking-widest uppercase">
                                        {t('shopping.focus_mode')}
                                    </div>
                                    <h2 className="font-heading mt-1 flex items-center gap-1.5 truncate text-2xl">
                                        <Store className="size-5" /> {shopTitle}
                                    </h2>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setFocus(false)}
                                    aria-label={t('shopping.exit_shopping_mode')}
                                    className="hover:bg-primary-foreground/10 flex size-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>
                        </div>
                        <div className="flex-1 space-y-6 overflow-y-auto px-5 pb-[calc(2rem+env(safe-area-inset-bottom))]">
                            {groups.map((g) => {
                                const AisleIcon = g.aisle ? aisleIcon(g.aisle.icon) : null;
                                return (
                                    <section key={g.aisle?.slug ?? 'all'} className="space-y-2">
                                        {g.aisle && AisleIcon && (
                                            <h3 className="text-accent-strong-foreground/60 flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                                <AisleIcon className="size-3.5" /> {g.aisle.name}
                                            </h3>
                                        )}
                                        {g.items.map((i) => (
                                            <button
                                                key={i.id}
                                                type="button"
                                                onClick={() => toggle(i)}
                                                className="border-primary-foreground/15 hover:bg-primary-foreground/5 flex w-full items-center gap-3 rounded-2xl border p-4 text-left transition-colors"
                                            >
                                                <span className="border-primary-foreground/40 flex size-6 shrink-0 rounded-full border-2" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-medium">{i.name}</div>
                                                    <div className="text-accent-strong-foreground/60 text-sm">
                                                        {i.quantity ? `${i.quantity} · ` : ''}
                                                        {t('shopping.from_list', { list: i.listName })}
                                                    </div>
                                                </div>
                                            </button>
                                        ))}
                                    </section>
                                );
                            })}
                            {remaining === 0 && <p className="text-accent-strong-foreground/80 py-10 text-center">{t('shopping.everything_done')}</p>}
                        </div>
                    </div>,
                    document.body,
                )}

            {/* Item editor (add / edit) */}
            <ResponsiveModal
                open={editingId !== null}
                onOpenChange={(v) => {
                    if (v) return;
                    if (editingId && draft.name.trim()) patchItem(editingId); // flush the last edit
                    setEditingId(null);
                }}
                title={editingId ? t('shopping.edit_item') : t('shopping.add_item_title')}
                footer={
                    editingId ? undefined : (
                        <Button className="w-full" disabled={!draft.name.trim() || adding} onClick={addItem}>
                            <Plus className="size-4" /> {t('shopping.add_item')}
                        </Button>
                    )
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.item_label')}</Label>
                        <Input
                            autoFocus
                            value={draft.name}
                            onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
                            placeholder={t('shopping.item_placeholder_shop')}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.quantity')}</Label>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => bump(-1)}
                                aria-label={t('shopping.decrease')}
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Minus className="size-4" />
                            </button>
                            <Input
                                value={draft.quantity}
                                onChange={(e) => setDraft((d) => ({ ...d, quantity: e.target.value }))}
                                placeholder={t('shopping.quantity_placeholder')}
                                className="text-center"
                            />
                            <button
                                type="button"
                                onClick={() => bump(1)}
                                aria-label={t('shopping.increase')}
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Plus className="size-4" />
                            </button>
                        </div>
                    </div>

                    {!editingId && lists.length > 0 && (
                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                {t('shopping.list_label')}
                            </Label>
                            <Picker
                                placeholder={t('shopping.pick_list')}
                                options={lists.map((l) => ({ value: String(l.id), label: l.name }))}
                                selected={draft.list_id ? [String(draft.list_id)] : []}
                                onChange={(next) => setDraft((d) => ({ ...d, list_id: next[0] ? Number(next[0]) : null }))}
                            />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.tab_shops')}</Label>
                        <Picker
                            multi
                            searchable
                            placeholder={t('shopping.pick_shops')}
                            options={shopOptions}
                            selected={draft.shops}
                            onChange={(next) => setDraft((d) => ({ ...d, shops: [...new Set(next)] }))}
                            onCreate={() => {}}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('shopping.department_label')}
                        </Label>
                        <Picker
                            placeholder={t('shopping.pick_department')}
                            options={departmentOptions}
                            selected={[draft.department]}
                            onChange={(next) => setDraft((d) => ({ ...d, department: next[0] ?? 'other' }))}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.note_label')}</Label>
                        <Input
                            value={draft.note}
                            onChange={(e) => setDraft((d) => ({ ...d, note: e.target.value }))}
                            placeholder={t('shopping.note_placeholder')}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('shopping.due_date_label')}
                        </Label>
                        <div className="border-border flex h-10 w-full items-center gap-2 rounded-md border px-3">
                            <Calendar className="text-muted-foreground size-4 shrink-0" />
                            <input
                                type="date"
                                value={draft.due_date}
                                onChange={(e) => setDraft((d) => ({ ...d, due_date: e.target.value }))}
                                className="text-primary w-full min-w-0 bg-transparent text-base outline-none md:text-sm"
                            />
                            {draft.due_date && (
                                <button
                                    type="button"
                                    onClick={() => setDraft((d) => ({ ...d, due_date: '' }))}
                                    className="text-muted-foreground hover:text-primary text-xs"
                                >
                                    {t('shopping.clear')}
                                </button>
                            )}
                        </div>
                    </div>

                    {!!editingId && (
                        <button
                            type="button"
                            onClick={deleteItem}
                            className="text-destructive flex items-center justify-center gap-2 py-1 text-sm font-medium"
                        >
                            <Trash2 className="size-4" /> {t('shopping.delete_item')}
                        </button>
                    )}
                </div>
            </ResponsiveModal>

            <ResponsiveModal open={settingsOpen} onOpenChange={setSettingsOpen} title={t('shopping.shop_settings')}>
                <div className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.name_label')}</label>
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onBlur={saveName}
                            onKeyDown={(e) => e.key === 'Enter' && saveName()}
                        />
                        <p className="text-text-muted text-xs">{t('shopping.rename_hint')}</p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            setSettingsOpen(false);
                            setAislesOpen(true);
                        }}
                    >
                        {t('shopping.edit_departments')}
                    </Button>
                </div>
            </ResponsiveModal>

            {!virtual && (
                <AisleEditor open={aislesOpen} onOpenChange={setAislesOpen} shopId={shop.id} shopName={shop.name} reloadKeys={['items', 'aisles']} />
            )}
        </AppLayout>
    );
}
