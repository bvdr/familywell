import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Pencil, RefreshCw, ShoppingCart, Sparkles } from 'lucide-react';
import { useState } from 'react';

import { CategoryTile, QtyBar, StateBadge } from '@/components/inventory';
import { ItemFormModal, QuickUpdateModal } from '@/components/inventory-modals';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { appLocale, useT, type TFn } from '@/lib/i18n';
import { byKey, catLabel, fmtNumber, locLabel, unitLabel, type InvCategory, type InvItem, type InvLocation } from '@/lib/inventory';
import { relativeTime } from '@/lib/notifications';
import { save } from '@/lib/save';
import type { BreadcrumbItem } from '@/types';

type HistoryEntry = { id: number; type: string; delta: number | null; user: string | null; at: string | null };
type Props = { item: InvItem; categories: InvCategory[]; locations: InvLocation[]; shops: string[]; history: HistoryEntry[] };

export default function InventoryItem({ item, categories, locations, shops, history }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const locMap = byKey(locations);
    const [updating, setUpdating] = useState(false);
    const [editing, setEditing] = useState(false);
    // Optimistic quantity overlay: the Quick-Update sheet applies the new amount here the moment Save is
    // tapped, so the hero number + "Current" row update instantly; state/bar stay server-derived and catch
    // up on the reload. Cleared once the request settles (see the modal's onReconcile below).
    const qtyOpt = useOptimistic<number | null>();
    const shownQuantity = qtyOpt.get(item.id, item.quantity);
    const displayItem = { ...item, quantity: shownQuantity };
    // Optimistic "on a shopping list" flag for the Add-to-Shopping action (mirrors AddToShoppingButton).
    const [optAdded, setOptAdded] = useState(false);
    const onShoppingList = item.onShoppingList || optAdded;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: item.name, href: `/m/inventory/items/${item.id}` },
    ];

    const rows: { label: string; value: string }[] = [
        { label: t('inventory.current'), value: shownQuantity === null ? t('inventory.qty_unknown') : `${fmtNumber(shownQuantity)} ${unitLabel(t, item.unit)}` },
        { label: t('inventory.minimum'), value: item.minQuantity > 0 ? `${fmtNumber(item.minQuantity)} ${unitLabel(t, item.unit)}` : '—' },
        { label: t('inventory.target'), value: item.targetQuantity ? `${fmtNumber(item.targetQuantity)} ${unitLabel(t, item.unit)}` : '—' },
        { label: t('inventory.category_label'), value: catLabel(t, item.category, catMap) },
        { label: t('inventory.location_label'), value: item.location ? locLabel(t, item.location, locMap) : t('inventory.no_location') },
        { label: t('inventory.expires_label'), value: item.expiresAt ? new Date(item.expiresAt + 'T00:00:00').toLocaleDateString(appLocale()) : t('inventory.no_expiry') },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={item.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <Link href="/m/inventory/items" className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <ArrowLeft className="size-4" /> {t('inventory.all_items')}
                </Link>

                {/* Hero: what it is, how much is left, and its state at a glance. */}
                <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
                    <div className="flex items-start gap-3">
                        <CategoryTile category={catMap[item.category ?? '']} />
                        <div className="min-w-0 flex-1">
                            <h1 className="font-heading text-primary truncate text-2xl">{item.name}</h1>
                            <div className="mt-1">
                                <StateBadge state={item.state} />
                            </div>
                        </div>
                    </div>

                    <div className="flex items-end justify-between gap-3">
                        <div className="text-primary text-3xl font-semibold tabular-nums">
                            {shownQuantity === null ? (
                                <span className="text-muted-foreground text-2xl">{t('inventory.qty_unknown')}</span>
                            ) : (
                                <>
                                    {fmtNumber(shownQuantity)}
                                    {item.targetQuantity ? <span className="text-muted-foreground text-xl"> {t('inventory.of')} {fmtNumber(item.targetQuantity)}</span> : null}
                                    <span className="text-muted-foreground ml-1 text-lg">{unitLabel(t, item.unit)}</span>
                                </>
                            )}
                        </div>
                    </div>
                    <QtyBar item={displayItem} className="h-2" />
                </section>

                {/* Actions. Update quantity + Edit + Add to Shopping are live; Create Task stays inert. */}
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <ActionButton icon={RefreshCw} label={t('inventory.update_quantity')} onClick={() => setUpdating(true)} primary />
                    <ActionButton icon={Pencil} label={t('inventory.edit_item')} onClick={() => setEditing(true)} />
                    {onShoppingList ? (
                        // Already on a list (or optimistically added): link straight to it, in the "added" (success) treatment.
                        <Link
                            href={item.shoppingListId ? `/m/shopping/lists/${item.shoppingListId}` : '/m/shopping'}
                            className="flex items-center justify-center gap-1.5 rounded-xl border border-[var(--success)] px-2 py-2.5 text-xs font-medium text-[var(--success)]"
                        >
                            <Check className="size-4" /> <span className="truncate">{t('inventory.shopping_added')}</span>
                        </Link>
                    ) : (
                        <ActionButton
                            icon={ShoppingCart}
                            label={t('inventory.add_to_shopping')}
                            onClick={() => {
                                setOptAdded(true); // instant "added" state; reconcile below
                                void save('post', route('inventory.items.shopping', item.id), {}).then(() => setOptAdded(false));
                            }}
                        />
                    )}
                    <ActionButton icon={Sparkles} label={t('inventory.create_task')} disabled t={t} />
                </div>

                {/* Info grid. */}
                <section className="bg-border grid grid-cols-2 gap-px overflow-hidden rounded-2xl border sm:grid-cols-3">
                    {rows.map((r) => (
                        <div key={r.label} className="bg-surface-card flex flex-col gap-1 px-4 py-3">
                            <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{r.label}</span>
                            <span className="text-primary text-sm">{r.value}</span>
                        </div>
                    ))}
                </section>

                {item.notes && (
                    <section className="flex flex-col gap-1">
                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('inventory.notes_label')}</h2>
                        <p className="text-text-secondary bg-surface-card rounded-xl border px-4 py-3 text-sm whitespace-pre-wrap">{item.notes}</p>
                    </section>
                )}

                {/* Replenishment automation, explained in words (the wiring itself lands in Phase 4). */}
                <section className="bg-accent-subtle/60 flex flex-col gap-1 rounded-2xl border p-4">
                    <h2 className="text-primary font-heading text-base">{t('inventory.automation_title')}</h2>
                    <p className="text-text-secondary text-sm">{automationText(t, item)}</p>
                    <p className="text-muted-foreground text-xs">{t('inventory.automation_note')}</p>
                </section>

                {/* Recent history for this item. */}
                <section className="flex flex-col gap-2">
                    <h2 className="font-heading text-primary text-lg">{t('inventory.history')}</h2>
                    {history.length === 0 ? (
                        <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('inventory.history_empty')}</p>
                    ) : (
                        <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                            {history.map((h) => (
                                <li key={h.id} className="bg-surface-card flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <span className="text-text-secondary">
                                        {t(`inventory.log_${h.type}`)}
                                        {h.delta !== null ? <span className="text-muted-foreground tabular-nums"> {h.delta > 0 ? '+' : ''}{fmtNumber(h.delta)} {unitLabel(t, item.unit)}</span> : null}
                                        {h.user ? <span className="text-muted-foreground"> · {h.user}</span> : null}
                                    </span>
                                    <span className="text-muted-foreground shrink-0 text-xs">{h.at ? relativeTime(h.at) : ''}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <QuickUpdateModal
                open={updating}
                onOpenChange={setUpdating}
                item={item}
                onOptimistic={(nextQty) => qtyOpt.set(item.id, nextQty)}
                onReconcile={() => qtyOpt.clear(item.id)}
            />
            <ItemFormModal open={editing} onOpenChange={setEditing} item={item} categories={categories} locations={locations} shops={shops} names={[item.name]} />
        </AppLayout>
    );
}

// The plain-English replenishment explanation: on with a min, on without a min, or off.
function automationText(t: TFn, item: InvItem): string {
    if (!item.autoReplenish) return t('inventory.automation_off');
    if (item.minQuantity > 0) return t('inventory.automation_on', { min: fmtNumber(item.minQuantity), unit: unitLabel(t, item.unit) });
    return t('inventory.automation_no_min');
}

function ActionButton({
    icon: Icon,
    label,
    onClick,
    disabled = false,
    primary = false,
    t,
}: {
    icon: typeof RefreshCw;
    label: string;
    onClick?: () => void;
    disabled?: boolean;
    primary?: boolean;
    t?: TFn;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={disabled ? t?.('inventory.coming_soon') : undefined}
            className={`flex items-center justify-center gap-1.5 rounded-xl border px-2 py-2.5 text-xs font-medium transition-colors ${
                disabled
                    ? 'text-primary opacity-50'
                    : primary
                      ? 'bg-accent-strong text-accent-strong-foreground border-transparent'
                      : 'text-primary hover:bg-surface'
            }`}
        >
            <Icon className="size-4" /> <span className="truncate">{label}</span>
        </button>
    );
}
