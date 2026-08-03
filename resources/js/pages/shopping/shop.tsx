import { Head, Link, router } from '@inertiajs/react';
import { Calendar, Check, ChevronLeft, Minus, Plus, Settings, Store, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { AisleEditor } from '@/components/aisle-editor';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useAddAction } from '@/lib/add-action';
import AppLayout from '@/layouts/app-layout';
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
    shop,
    aisles,
    items,
    shops = [],
    lists = [],
    defaultListId = null,
}: {
    shop: Shop;
    aisles: Aisle[];
    items: Item[];
    shops?: string[];
    lists?: ListRef[];
    defaultListId?: number | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shopping', href: '/m/shopping' },
        { title: 'Shops', href: '/m/shopping/shops' },
        { title: shop.name, href: `/m/shopping/shops/${shop.id}` },
    ];

    const boughtOpt = useOptimistic<boolean>();
    const isBought = (i: Item) => boughtOpt.get(i.id, i.bought);
    const toggle = (i: Item) => {
        boughtOpt.set(i.id, !isBought(i));
        // Empty-body PATCH flips `bought` on the item in its own list.
        router.patch(route('shopping.items.update', i.id), {}, { preserveScroll: true, preserveState: true, onSuccess: () => boughtOpt.clear(i.id), onError: () => boughtOpt.clear(i.id) });
    };

    // Item editor: null = closed, 0 = new item at this shop, >0 = editing that id.
    const [editingId, setEditingId] = useState<number | null>(null);
    const emptyDraft = (): Draft => ({ name: '', quantity: '', department: aisles[0]?.slug ?? 'other', note: '', due_date: '', shops: [shop.name], list_id: defaultListId });
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
            shops: shopsSel.length ? shopsSel : [shop.name],
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
            store: shopsUniq[0] ?? shop.name,
            alt_stores: shopsUniq.slice(1),
        };
    };
    const patchItem = (id: number) => router.patch(route('shopping.items.update', id), buildPayload(draft), { preserveScroll: true, preserveState: true });
    const addItem = () => {
        if (!draft.name.trim()) return;
        router.post(route('shopping.shops.items.store', shop.id), { ...buildPayload(draft), list_id: draft.list_id }, { preserveScroll: true, onSuccess: () => setEditingId(null) });
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
        router.delete(route('shopping.items.destroy', id), { preserveScroll: true });
    };
    const bump = (delta: number) =>
        setDraft((d) => {
            const n = parseInt(d.quantity, 10);
            return { ...d, quantity: String(Math.max(0, (isNaN(n) ? 0 : n) + delta)) };
        });

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [aislesOpen, setAislesOpen] = useState(false);
    const [name, setName] = useState(shop.name);
    const saveName = () => {
        const n = name.trim();
        if (n && n !== shop.name) router.patch(route('shopping.shops.update', shop.id), { name: n }, { preserveScroll: true });
    };

    // Split into "to buy" (grouped by the shop's aisles, walk order) and a bought pile at the bottom.
    // Computed each render (not memoised) so an optimistic check moves the item immediately.
    const toBuy = items.filter((i) => !isBought(i));
    const done = items.filter((i) => isBought(i));
    const bySlug = new Map(aisles.map((a) => [a.slug, a]));
    const otherSlug = bySlug.has('other') ? 'other' : aisles[aisles.length - 1]?.slug;
    const groups = aisles
        .map((a) => ({ aisle: a, items: toBuy.filter((i) => (bySlug.has(i.department) ? i.department : otherSlug) === a.slug) }))
        .filter((g) => g.items.length > 0);
    const remaining = toBuy.length;

    const departmentOptions = aisles.map((a) => ({ value: a.slug, label: a.name, icon: aisleIcon(a.icon) }));
    const shopOptions = [...new Set([...shops, ...draft.shops].filter(Boolean))].map((s) => ({ value: s, label: s, icon: Store }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={shop.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Link href="/m/shopping/shops" aria-label="Back to shops" className="text-muted-foreground hover:text-primary -ml-1 flex size-8 items-center justify-center">
                        <ChevronLeft className="size-5" />
                    </Link>
                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-xl">
                        <Store className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary truncate text-2xl">{shop.name}</h1>
                        <p className="text-muted-foreground text-sm">{remaining} to buy</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSettingsOpen(true)}
                        aria-label="Shop settings"
                        className="border-border text-primary hover:bg-surface flex size-10 items-center justify-center rounded-full border"
                    >
                        <Settings className="size-5" />
                    </button>
                </div>

                {items.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Nothing needed at {shop.name} yet. Add the first item below.
                    </div>
                )}

                {groups.map((g) => {
                    const Icon = aisleIcon(g.aisle.icon);
                    return (
                        <div key={g.aisle.slug} className="flex flex-col gap-2">
                            <div className="text-muted-foreground flex items-center gap-1.5 px-1 text-xs font-semibold tracking-widest uppercase">
                                <Icon className="size-4" /> {g.aisle.name}
                            </div>
                            {g.items.map((i) => (
                                <div key={i.id} className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-2.5">
                                    <button type="button" onClick={() => toggle(i)} aria-label="Mark bought" className="border-border flex size-6 shrink-0 items-center justify-center rounded-full border" />
                                    {/* Tap the body to edit the item. */}
                                    <button type="button" onClick={() => openEdit(i)} className="min-w-0 flex-1 text-left">
                                        <span className="text-primary block truncate text-[15px]">{i.name}</span>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {i.quantity ? `${i.quantity} · ` : ''}
                                            from {i.listName}
                                        </span>
                                    </button>
                                </div>
                            ))}
                        </div>
                    );
                })}

                {done.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <div className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">In the cart</div>
                        {done.map((i) => (
                            <button
                                key={i.id}
                                type="button"
                                onClick={() => toggle(i)}
                                className="bg-surface flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left opacity-70"
                            >
                                <span className="border-primary bg-primary text-primary-foreground flex size-6 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-3.5" />
                                </span>
                                <span className="text-muted-foreground min-w-0 flex-1 truncate text-[15px] line-through">{i.name}</span>
                            </button>
                        ))}
                    </div>
                )}

                {/* Add an item at this shop (goes to your default list unless you pick another). */}
                <Button type="button" variant="outline" onClick={openNew} className="mt-1">
                    <Plus className="size-4" /> Add item
                </Button>
            </div>

            {/* Item editor (add / edit) */}
            <ResponsiveModal
                open={editingId !== null}
                onOpenChange={(v) => {
                    if (v) return;
                    if (editingId && draft.name.trim()) patchItem(editingId); // flush the last edit
                    setEditingId(null);
                }}
                title={editingId ? 'Edit Item' : 'Add Item'}
                footer={
                    editingId ? undefined : (
                        <Button className="w-full" disabled={!draft.name.trim()} onClick={addItem}>
                            <Plus className="size-4" /> Add item
                        </Button>
                    )
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Item</Label>
                        <Input autoFocus value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} placeholder="What do you need?" />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Quantity</Label>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => bump(-1)} aria-label="Decrease" className="border-border text-primary flex size-9 items-center justify-center rounded-lg border">
                                <Minus className="size-4" />
                            </button>
                            <Input value={draft.quantity} onChange={(e) => setDraft((d) => ({ ...d, quantity: e.target.value }))} placeholder="2 bunches" className="text-center" />
                            <button type="button" onClick={() => bump(1)} aria-label="Increase" className="border-border text-primary flex size-9 items-center justify-center rounded-lg border">
                                <Plus className="size-4" />
                            </button>
                        </div>
                    </div>

                    {!editingId && lists.length > 0 && (
                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">List</Label>
                            <Picker
                                placeholder="Pick a list"
                                options={lists.map((l) => ({ value: String(l.id), label: l.name }))}
                                selected={draft.list_id ? [String(draft.list_id)] : []}
                                onChange={(next) => setDraft((d) => ({ ...d, list_id: next[0] ? Number(next[0]) : null }))}
                            />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Shops</Label>
                        <Picker
                            multi
                            searchable
                            placeholder="Pick shops"
                            options={shopOptions}
                            selected={draft.shops}
                            onChange={(next) => setDraft((d) => ({ ...d, shops: [...new Set(next)] }))}
                            onCreate={() => {}}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Department</Label>
                        <Picker placeholder="Pick a department" options={departmentOptions} selected={[draft.department]} onChange={(next) => setDraft((d) => ({ ...d, department: next[0] ?? 'other' }))} />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Note</Label>
                        <Input value={draft.note} onChange={(e) => setDraft((d) => ({ ...d, note: e.target.value }))} placeholder="Add a note (optional)" />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Due date</Label>
                        <div className="border-border flex h-10 w-full items-center gap-2 rounded-md border px-3">
                            <Calendar className="text-muted-foreground size-4 shrink-0" />
                            <input type="date" value={draft.due_date} onChange={(e) => setDraft((d) => ({ ...d, due_date: e.target.value }))} className="text-primary w-full min-w-0 bg-transparent text-base outline-none md:text-sm" />
                            {draft.due_date && (
                                <button type="button" onClick={() => setDraft((d) => ({ ...d, due_date: '' }))} className="text-muted-foreground hover:text-primary text-xs">
                                    Clear
                                </button>
                            )}
                        </div>
                    </div>

                    {!!editingId && (
                        <button type="button" onClick={deleteItem} className="text-destructive flex items-center justify-center gap-2 py-1 text-sm font-medium">
                            <Trash2 className="size-4" /> Delete item
                        </button>
                    )}
                </div>
            </ResponsiveModal>

            <ResponsiveModal open={settingsOpen} onOpenChange={setSettingsOpen} title="Shop settings">
                <div className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Name</label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} onBlur={saveName} onKeyDown={(e) => e.key === 'Enter' && saveName()} />
                        <p className="text-text-muted text-xs">Renaming updates every item, list and department that uses this shop.</p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            setSettingsOpen(false);
                            setAislesOpen(true);
                        }}
                    >
                        Edit departments
                    </Button>
                </div>
            </ResponsiveModal>

            <AisleEditor open={aislesOpen} onOpenChange={setAislesOpen} shopId={shop.id} shopName={shop.name} reloadKeys={['items', 'aisles']} />
        </AppLayout>
    );
}
