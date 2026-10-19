import { router, useForm } from '@inertiajs/react';
import { MapPin, Minus, Package, Plus, Ruler, Store, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import InputError from '@/components/input-error';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT, type TFn } from '@/lib/i18n';
import { byKey, catLabel, fmtNumber, locLabel, unitLabel, type InvCategory, type InvItem, type InvLocation, type InvUnit } from '@/lib/inventory';
import { save as saveInBackground } from '@/lib/save';

// A field label styled like the rest of the app's form controls.
function FieldLabel({ children }: { children: React.ReactNode }) {
    return <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{children}</Label>;
}

// The units a quantity can be measured in (mirrors InventoryItem::UNITS). Localised via unitLabel.
const UNITS: InvUnit[] = ['pieces', 'g', 'kg', 'ml', 'L', 'packs', 'bottles', 'boxes'];

// Unit-aware quick-step presets, used by both the Add/Edit quantity stepper (the first value) and the
// Quick-Update buttons (all three). Discrete things step by whole counts; grams/ml by their natural
// increments. Anything not listed falls back to whole numbers.
const STEP_PRESETS: Record<string, number[]> = {
    pieces: [1, 2, 5],
    packs: [1, 2, 5],
    bottles: [1, 2, 5],
    boxes: [1, 2, 5],
    g: [10, 25, 50],
    kg: [0.25, 0.5, 1],
    ml: [50, 100, 250],
    L: [0.25, 0.5, 1],
};
const presetsFor = (unit: string): number[] => STEP_PRESETS[unit] ?? [1, 2, 5];
const stepFor = (unit: string): number => presetsFor(unit)[0];

// Parse a numeric text field to a number, treating blank/garbage as 0 (for the stepper maths).
const num = (v: string): number => {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
};
// Trim a computed number back to a clean field string (avoids 0.1+0.2 float noise).
const clean = (n: number): string => String(Number(Math.max(0, n).toFixed(2)));

type ItemFields = {
    name: string;
    quantity: string; // '' => unknown
    unit: string;
    category: string;
    location: string;
    shop: string; // shop NAME (as Shopping stores it); '' => none
    min_quantity: string;
    target_quantity: string;
    expires_at: string;
    auto_replenish: boolean;
    notes: string;
};

function initialFields(item?: InvItem | null): ItemFields {
    return {
        name: item?.name ?? '',
        quantity: item?.quantity == null ? '' : fmtNumber(item.quantity),
        unit: item?.unit ?? 'pieces',
        category: item?.category ?? '',
        location: item?.location ?? '',
        shop: item?.shop ?? '',
        min_quantity: item?.minQuantity ? fmtNumber(item.minQuantity) : '',
        target_quantity: item?.targetQuantity ? fmtNumber(item.targetQuantity) : '',
        expires_at: item?.expiresAt ?? '',
        auto_replenish: item?.autoReplenish ?? true,
        notes: item?.notes ?? '',
    };
}

/**
 * Add / Edit item. One modal for both: pass an `item` to edit, omit it to add. Category and location
 * are pickers that also let you type a new one inline (resolved server-side to a key). Deleting lives
 * behind a two-step confirm at the very bottom, well away from Save.
 */
export function ItemFormModal({
    open,
    onOpenChange,
    item,
    categories,
    locations,
    shops = [],
    names = [],
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    item?: InvItem | null;
    categories: InvCategory[];
    locations: InvLocation[];
    shops?: string[]; // the family's shop names (Shopping's ShoppingShop rows), for the shop picker
    names?: string[]; // existing item names, for the name autocomplete
}) {
    const t = useT();
    const editing = !!item;
    const catMap = byKey(categories);
    const locMap = byKey(locations);
    const nameRef = useRef<HTMLInputElement>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [extraShops, setExtraShops] = useState<string[]>([]); // shops typed in this session (same as Shopping's item sheet)

    const { data, setData, post, patch, processing, errors, reset, transform } = useForm<ItemFields>(initialFields(item));

    // Re-seed the form whenever the modal opens (so Add starts blank and Edit reflects the current item,
    // even after a prior edit). Only on the open transition — not on every keystroke.
    useEffect(() => {
        if (open) {
            setData(initialFields(item));
            setConfirmDelete(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item?.id]);

    const step = stepFor(data.unit);
    const bump = (by: number) => setData('quantity', clean(num(data.quantity) + by));

    const submit = () => {
        transform((d) => ({
            ...d,
            name: d.name.trim(),
            quantity: d.quantity === '' ? null : num(d.quantity),
            min_quantity: d.min_quantity === '' ? 0 : num(d.min_quantity),
            target_quantity: d.target_quantity === '' ? null : num(d.target_quantity),
            category: d.category || null,
            location: d.location || null,
            shop: d.shop || null,
            expires_at: d.expires_at || null,
            notes: d.notes.trim() || null,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };
        if (editing) {
            patch(route('inventory.items.update', item!.id), opts);
        } else {
            post(route('inventory.items.store'), opts);
        }
    };

    const remove = () => {
        // Redirects to the items list (the detail page is about to disappear), so no onSuccess needed.
        router.delete(route('inventory.items.destroy', item!.id), { preserveScroll: false });
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? t('inventory.edit_item_title') : t('inventory.add_item_title')}
            autoFocusRef={nameRef}
            footer={
                <Button onClick={submit} disabled={processing || !data.name.trim()} className="w-full">
                    {editing ? t('inventory.save') : t('inventory.add_item')}
                </Button>
            }
        >
            <div className="flex flex-col gap-5 pb-1">
                {/* Name, with a datalist of existing item names for quick reuse. */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.name_label')}</FieldLabel>
                    <Input
                        ref={nameRef}
                        list="inv-item-names"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder={t('inventory.name_placeholder')}
                        className="text-base"
                    />
                    <datalist id="inv-item-names">
                        {names.map((n) => (
                            <option key={n} value={n} />
                        ))}
                    </datalist>
                    <InputError message={errors.name} />
                </div>

                {/* Quantity: a big −N+ stepper + unit dropdown; a link marks it "unknown" (no measured amount). */}
                <div className="grid gap-1.5">
                    <div className="flex items-center justify-between">
                        <FieldLabel>{t('inventory.quantity_label')}</FieldLabel>
                        {data.quantity === '' ? (
                            <button type="button" onClick={() => setData('quantity', '0')} className="text-primary text-xs font-medium">
                                {t('inventory.set_amount')}
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setData('quantity', '')}
                                className="text-muted-foreground hover:text-primary text-xs"
                            >
                                {t('inventory.mark_unknown')}
                            </button>
                        )}
                    </div>
                    {data.quantity === '' ? (
                        <p className="text-muted-foreground bg-surface rounded-lg border px-3 py-2.5 text-sm">{t('inventory.qty_unknown_hint')}</p>
                    ) : (
                        // The unit Picker expands inline, so it gets its own full-width line under the stepper.
                        <div className="flex flex-col gap-2">
                            <div className="border-border flex items-center overflow-hidden rounded-xl border">
                                <button
                                    type="button"
                                    onClick={() => bump(-step)}
                                    aria-label={t('inventory.decrease')}
                                    className="text-primary hover:bg-surface flex h-11 w-12 shrink-0 items-center justify-center"
                                >
                                    <Minus className="size-5" />
                                </button>
                                <input
                                    type="number"
                                    inputMode="decimal"
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                    className="text-primary h-11 w-full min-w-0 border-x bg-transparent text-center text-base tabular-nums outline-none"
                                />
                                <button
                                    type="button"
                                    onClick={() => bump(step)}
                                    aria-label={t('inventory.increase')}
                                    className="text-primary hover:bg-surface flex h-11 w-12 shrink-0 items-center justify-center"
                                >
                                    <Plus className="size-5" />
                                </button>
                            </div>
                            <UnitSelect value={data.unit} onChange={(v) => setData('unit', v)} t={t} />
                        </div>
                    )}
                    <InputError message={errors.quantity} />
                </div>

                {/* Category + Location: pick an existing one or type a new name (created on save). */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.category_label')}</FieldLabel>
                    <Picker
                        searchable
                        fallbackIcon={Package}
                        placeholder={t('inventory.pick_category')}
                        options={categories.map((c) => ({ value: c.key, label: catLabel(t, c.key, catMap) }))}
                        selected={data.category ? [data.category] : []}
                        onChange={(next) => setData('category', next[0] ?? '')}
                        onCreate={(name) => setData('category', name)}
                    />
                </div>

                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.location_label')}</FieldLabel>
                    <Picker
                        searchable
                        fallbackIcon={MapPin}
                        placeholder={t('inventory.pick_location')}
                        options={locations.map((l) => ({ value: l.key, label: locLabel(t, l.key, locMap) }))}
                        selected={data.location ? [data.location] : []}
                        onChange={(next) => setData('location', next[0] ?? '')}
                        onCreate={(name) => setData('location', name)}
                    />
                </div>

                {/* Shop: where the family buys this. Pick one of Shopping's shops or type a new name; the
                    shopping row created when the item runs low carries it as its store, so it files under
                    that shop in the Shops view. Optional. */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.shop_label')}</FieldLabel>
                    <Picker
                        searchable
                        fallbackIcon={Store}
                        placeholder={t('inventory.pick_shop')}
                        options={[...new Set([...shops, ...extraShops, data.shop].filter(Boolean))].map((s) => ({ value: s, label: s }))}
                        selected={data.shop ? [data.shop] : []}
                        onChange={(next) => setData('shop', next[0] ?? '')}
                        onCreate={(name) => setExtraShops((prev) => [...new Set([...prev, name])])}
                    />
                    <InputError message={errors.shop} />
                </div>

                {/* Minimum + target. Minimum drives the low state (and the Shopping loop); target is the
                    "full" level and also the quantity bar's denominator. */}
                <div className="grid grid-cols-2 gap-3">
                    <div className="grid gap-1.5">
                        <FieldLabel>{t('inventory.min_quantity_label')}</FieldLabel>
                        <NumberField value={data.min_quantity} onChange={(v) => setData('min_quantity', v)} unit={data.unit} t={t} />
                    </div>
                    <div className="grid gap-1.5">
                        <FieldLabel>{t('inventory.target_quantity_label')}</FieldLabel>
                        <NumberField value={data.target_quantity} onChange={(v) => setData('target_quantity', v)} unit={data.unit} t={t} />
                    </div>
                </div>
                <p className="text-muted-foreground -mt-3 text-xs">{t('inventory.min_helper')}</p>

                {/* Expiration (optional, native date). */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.expiration_label')}</FieldLabel>
                    <Input type="date" value={data.expires_at} onChange={(e) => setData('expires_at', e.target.value)} className="text-base" />
                </div>

                {/* Add to Shopping when low. Default ON — the core replenishment loop. */}
                <button
                    type="button"
                    onClick={() => setData('auto_replenish', !data.auto_replenish)}
                    className="border-border flex items-center justify-between gap-3 rounded-xl border p-3 text-left"
                >
                    <span className="min-w-0">
                        <span className="text-primary block text-sm font-medium">{t('inventory.auto_replenish_label')}</span>
                        <span className="text-muted-foreground block text-xs">{t('inventory.auto_replenish_hint')}</span>
                    </span>
                    <Toggle on={data.auto_replenish} />
                </button>

                {/* Notes. */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.notes_label')}</FieldLabel>
                    <textarea
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        rows={2}
                        placeholder={t('inventory.notes_placeholder')}
                        className="border-input bg-background text-primary focus-visible:border-ring focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base outline-none focus-visible:ring-2 md:text-sm"
                    />
                </div>

                {/* Delete — edit only, two-step, kept far from Save. */}
                {editing && (
                    <div className="border-border/60 mt-2 border-t pt-4">
                        {confirmDelete ? (
                            <div className="flex items-center gap-2">
                                <Button variant="destructive" onClick={remove} className="flex-1">
                                    <Trash2 className="size-4" /> {t('inventory.confirm_delete')}
                                </Button>
                                <Button variant="outline" onClick={() => setConfirmDelete(false)} className="flex-1">
                                    {t('inventory.cancel')}
                                </Button>
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setConfirmDelete(true)}
                                className="text-destructive flex items-center gap-1.5 text-sm font-medium"
                            >
                                <Trash2 className="size-4" /> {t('inventory.delete_item')}
                            </button>
                        )}
                    </div>
                )}
            </div>
        </ResponsiveModal>
    );
}

/**
 * Quick Quantity Update — the fast daily path. Shows the current amount, unit-aware −/+ quick buttons
 * (Consumed / Added), a manual entry, and the resulting new quantity. One field on save: the absolute
 * quantity the user landed on.
 */
export function QuickUpdateModal({
    open,
    onOpenChange,
    item,
    onOptimistic,
    onReconcile,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    item: InvItem;
    // Optimistic hooks: `onOptimistic(next)` lets the parent overlay the new quantity the instant Save is
    // tapped (so the item's shown amount updates now, not after the round-trip); `onReconcile` clears that
    // overlay once the server settles (success or error), handing authority back to the reloaded props.
    onOptimistic?: (next: number) => void;
    onReconcile?: () => void;
}) {
    const t = useT();
    const [qty, setQty] = useState<string>('0');

    // Start from the current amount each time it opens (unknown counts as 0 to start).
    useEffect(() => {
        if (open) setQty(item.quantity == null ? '0' : fmtNumber(item.quantity));
    }, [open, item.quantity]);

    const presets = useMemo(() => presetsFor(item.unit), [item.unit]);
    const nudge = (by: number) => setQty((q) => clean(num(q) + by));
    const current = item.quantity == null ? 0 : item.quantity;
    const next = num(qty);
    const delta = next - current;

    // Optimistic save: apply the new quantity + close the sheet immediately, then reconcile in the
    // background. The server derives state/bar from the quantity, so those catch up on the reload — we only
    // overlay the raw number we already know (never re-implementing the state rules client-side). Rolls the
    // overlay back on error so the shown amount snaps to the confirmed value instead of lying.
    const save = () => {
        const target = next;
        onOptimistic?.(target);
        onOpenChange(false);
        // lib/save: the next tap can't cancel it; onReconcile runs once the reload shows the real amount.
        void saveInBackground('post', route('inventory.items.adjust', item.id), { quantity: target }).then(() => onReconcile?.());
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('inventory.update_quantity')}
            description={item.name}
            footer={
                <Button onClick={save} className="w-full">
                    {t('inventory.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-5 pb-1">
                {/* Consumed − */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.consumed')}</FieldLabel>
                    <div className="grid grid-cols-3 gap-2">
                        {presets.map((p) => (
                            <button
                                key={`c${p}`}
                                type="button"
                                onClick={() => nudge(-p)}
                                className="border-border text-primary hover:bg-surface flex h-12 items-center justify-center rounded-xl border text-base font-medium tabular-nums"
                            >
                                −{fmtNumber(p)}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Added + */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.added')}</FieldLabel>
                    <div className="grid grid-cols-3 gap-2">
                        {presets.map((p) => (
                            <button
                                key={`a${p}`}
                                type="button"
                                onClick={() => nudge(p)}
                                className="border-border text-primary hover:bg-surface flex h-12 items-center justify-center rounded-xl border text-base font-medium tabular-nums"
                            >
                                +{fmtNumber(p)}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Manual entry. */}
                <div className="grid gap-1.5">
                    <FieldLabel>{t('inventory.set_exact')}</FieldLabel>
                    <div className="flex items-center gap-2">
                        <input
                            type="number"
                            inputMode="decimal"
                            value={qty}
                            onChange={(e) => setQty(e.target.value)}
                            className="border-input bg-background text-primary focus-visible:border-ring focus-visible:ring-ring h-11 w-full rounded-md border px-3 text-center text-base tabular-nums outline-none focus-visible:ring-2"
                        />
                        <span className="text-muted-foreground w-16 shrink-0 text-sm">{unitLabel(t, item.unit)}</span>
                    </div>
                </div>

                {/* Resulting new quantity, with the net change. */}
                <div className="bg-surface flex items-center justify-between rounded-xl border px-4 py-3">
                    <span className="text-muted-foreground text-sm">{t('inventory.new_quantity')}</span>
                    <span className="text-primary text-xl font-semibold tabular-nums">
                        {clean(next)} <span className="text-muted-foreground text-sm">{unitLabel(t, item.unit)}</span>
                        {Math.abs(delta) > 1e-9 && (
                            <span className={`ml-2 text-sm ${delta < 0 ? 'text-[var(--warning)]' : 'text-[var(--success)]'}`}>
                                ({delta > 0 ? '+' : ''}
                                {fmtNumber(Number(delta.toFixed(2)))})
                            </span>
                        )}
                    </span>
                </div>
            </div>
        </ResponsiveModal>
    );
}

// The unit dropdown, on its own line below the stepper.
function UnitSelect({ value, onChange, t }: { value: string; onChange: (v: string) => void; t: TFn }) {
    return (
        <Picker
            options={UNITS.map((u) => ({ value: u, label: unitLabel(t, u) }))}
            selected={[value]}
            onChange={(next) => next[0] && onChange(next[0])}
            ariaLabel={t('inventory.unit_label')}
            fallbackIcon={Ruler}
        />
    );
}

// A plain optional numeric field (min / target), with the unit shown as a suffix hint.
function NumberField({ value, onChange, unit, t }: { value: string; onChange: (v: string) => void; unit: string; t: TFn }) {
    return (
        <div className="border-input bg-background focus-within:border-ring focus-within:ring-ring flex items-center rounded-md border pr-3 focus-within:ring-2">
            <input
                type="number"
                inputMode="decimal"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="—"
                className="text-primary h-10 w-full min-w-0 rounded-md bg-transparent px-3 text-base tabular-nums outline-none md:text-sm"
            />
            <span className="text-muted-foreground shrink-0 text-xs">{unitLabel(t, unit)}</span>
        </div>
    );
}

// A small on/off pill for the auto-replenish toggle.
function Toggle({ on }: { on: boolean }) {
    return (
        <span className={`flex h-6 w-10 shrink-0 items-center rounded-full px-0.5 transition-colors ${on ? 'bg-accent-strong' : 'bg-border'}`}>
            <span className={`size-5 rounded-full bg-white transition-transform ${on ? 'translate-x-4' : ''}`} />
        </span>
    );
}
