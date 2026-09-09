import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronRight, LayoutGrid, MapPin, Package, Plus, Settings, ShoppingCart } from 'lucide-react';
import { useState } from 'react';

import { CategoryTile, QtyBar, QtyText, StateBadge } from '@/components/inventory';
import { ItemFormModal } from '@/components/inventory-modals';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { useT, type TFn } from '@/lib/i18n';
import { byKey, catLabel, type InvCategory, type InvItem, type InvLocation } from '@/lib/inventory';
import { relativeTime } from '@/lib/notifications';
import type { BreadcrumbItem } from '@/types';

type Summary = { total: number; low: number; empty: number; expiring: number };
type Recent = { id: number; itemId: number; itemName: string; unit: string; type: string; delta: number | null; user: string | null; at: string | null };
type CatCounts = Record<string, { count: number; attention: number }>;

type Props = {
    summary: Summary;
    attention: InvItem[];
    recent: Recent[];
    categories: InvCategory[];
    locations: InvLocation[];
    categoryCounts: CatCounts;
    itemNames: string[];
    shops: string[];
};

export default function InventoryOverview({ summary, attention, recent, categories, locations, categoryCounts, itemNames, shops }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('inventory.title'), href: '/m/inventory' }];
    const [adding, setAdding] = useState(openNewOnArrival);
    useAddAction(() => setAdding(true)); // the mobile nav "+" adds an item here

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">{t('inventory.title')}</h1>
                        <p className="text-muted-foreground text-sm">{t('inventory.subtitle')}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {/* Browse/manage the family's categories + locations, and open module settings. */}
                        <IconLink href="/m/inventory/categories" label={t('inventory.view_categories')} icon={LayoutGrid} />
                        <IconLink href="/m/inventory/locations" label={t('inventory.view_locations')} icon={MapPin} />
                        <IconLink href="/m/inventory/settings" label={t('inventory.settings')} icon={Settings} />
                        <AddItemButton t={t} onClick={() => setAdding(true)} />
                    </div>
                </header>

                {summary.total === 0 ? (
                    <EmptyState t={t} onAdd={() => setAdding(true)} />
                ) : (
                    <>
                        <SummaryCards summary={summary} t={t} />

                        {/* Replenishment shortcut: the core loop, one tap from the overview when stock is short. */}
                        {summary.low + summary.empty > 0 && (
                            <Link
                                href="/m/inventory/replenishment"
                                className="bg-accent-subtle/60 hover:bg-accent-subtle flex items-center gap-3 rounded-2xl border px-4 py-3 transition-colors"
                            >
                                <span className="bg-accent-strong text-accent-strong-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <ShoppingCart className="size-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="text-primary block text-sm font-medium">{t('inventory.replenishment_title')}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {t('inventory.replenishment_subtitle', { count: summary.low + summary.empty })}
                                    </span>
                                </span>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                            </Link>
                        )}

                        {/* Needs attention: the whole point — what to act on, most-urgent first. */}
                        <section className="flex flex-col gap-2">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="text-[var(--warning)] size-4" />
                                <h2 className="font-heading text-primary text-lg">{t('inventory.needs_attention')}</h2>
                                {attention.length > 0 && (
                                    <Link href="/m/inventory/needs-attention" className="text-primary ml-auto flex items-center gap-0.5 text-xs font-medium">
                                        {t('inventory.view_needs_attention')} <ChevronRight className="size-3.5" />
                                    </Link>
                                )}
                            </div>
                            {attention.length === 0 ? (
                                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">
                                    {t('inventory.needs_attention_empty')}
                                </p>
                            ) : (
                                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                                    {attention.map((item) => (
                                        <li key={item.id}>
                                            <Link
                                                href={`/m/inventory/items/${item.id}`}
                                                className="bg-surface-card flex items-center gap-3 px-4 py-3"
                                            >
                                                <CategoryTile category={catMap[item.category ?? '']} size="sm" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-primary block truncate text-[15px]">{item.name}</span>
                                                    <span className="mt-1 block">
                                                        <QtyBar item={item} />
                                                    </span>
                                                </span>
                                                <span className="shrink-0 text-right text-sm">
                                                    <span className="block">
                                                        <QtyText item={item} />
                                                    </span>
                                                    <StateBadge state={item.state} className="mt-1" />
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {/* Recently updated: the household's activity feed, read from the log. */}
                        <section className="flex flex-col gap-2">
                            <h2 className="font-heading text-primary text-lg">{t('inventory.recently_updated')}</h2>
                            {recent.length === 0 ? (
                                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">
                                    {t('inventory.recent_empty')}
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-1.5">
                                    {recent.map((r) => (
                                        <li key={r.id}>
                                            <Link
                                                href={`/m/inventory/items/${r.itemId}`}
                                                className="text-text-secondary flex items-center gap-2 px-1 text-sm"
                                            >
                                                <span className="text-muted-foreground w-16 shrink-0 text-xs">{r.at ? relativeTime(r.at) : ''}</span>
                                                <span className="min-w-0 flex-1 truncate">
                                                    <span className="text-primary">{r.itemName}</span>{' '}
                                                    <span className="text-muted-foreground">
                                                        {t(`inventory.log_${r.type}`)}
                                                        {r.delta !== null ? ` (${r.delta > 0 ? '+' : ''}${r.delta})` : ''}
                                                        {r.user ? ` · ${r.user}` : ''}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {/* Browse by category: each card shows its item count + how many need attention. */}
                        <section className="flex flex-col gap-2">
                            <h2 className="font-heading text-primary text-lg">{t('inventory.browse_categories')}</h2>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {categories
                                    .filter((c) => (categoryCounts[c.key]?.count ?? 0) > 0)
                                    .map((c) => {
                                        const counts = categoryCounts[c.key] ?? { count: 0, attention: 0 };
                                        return (
                                            <Link
                                                key={c.key}
                                                href={`/m/inventory/items?category=${encodeURIComponent(c.key)}`}
                                                className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-3"
                                            >
                                                <CategoryTile category={c} size="sm" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-primary block truncate text-sm">{catLabel(t, c.key, catMap)}</span>
                                                    <span className="text-muted-foreground block text-xs">
                                                        {t('inventory.items_count', { count: counts.count })}
                                                        {counts.attention > 0 ? ` · ${t('inventory.low_count', { count: counts.attention })}` : ''}
                                                    </span>
                                                </span>
                                            </Link>
                                        );
                                    })}
                            </div>
                        </section>

                        <Link
                            href="/m/inventory/items"
                            className="text-primary flex items-center justify-center gap-1.5 rounded-xl border py-2.5 text-sm font-medium"
                        >
                            {t('inventory.view_all_items')} <ChevronRight className="size-4" />
                        </Link>
                    </>
                )}
            </div>

            <ItemFormModal open={adding} onOpenChange={setAdding} categories={categories} locations={locations} shops={shops} names={itemNames} />
        </AppLayout>
    );
}

// The four compact summary cards. A count of 0 stays muted; a non-zero attention count picks up its
// state colour so "3 empty" reads as a problem at a glance.
function SummaryCards({ summary, t }: { summary: Summary; t: TFn }) {
    const cards: { label: string; value: number; color?: string }[] = [
        { label: t('inventory.summary_items'), value: summary.total },
        { label: t('inventory.summary_low'), value: summary.low, color: 'var(--warning)' },
        { label: t('inventory.summary_empty'), value: summary.empty, color: 'var(--destructive)' },
        { label: t('inventory.summary_expiring'), value: summary.expiring, color: 'var(--warning)' },
    ];
    return (
        <section className="bg-border grid grid-cols-2 gap-px overflow-hidden rounded-2xl border sm:grid-cols-4">
            {cards.map((c) => (
                <div key={c.label} className="bg-surface-card flex flex-col gap-1 px-4 py-4">
                    <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{c.label}</span>
                    <span
                        className="text-2xl font-semibold tabular-nums"
                        style={{ color: c.value > 0 && c.color ? c.color : 'var(--primary)' }}
                    >
                        {c.value}
                    </span>
                </div>
            ))}
        </section>
    );
}

// A small square outline link (Categories / Locations) that sits beside the Add button in the header.
function IconLink({ href, label, icon: Icon }: { href: string; label: string; icon: typeof Package }) {
    return (
        <Link
            href={href}
            title={label}
            aria-label={label}
            className="border-border text-primary hover:bg-surface flex size-9 shrink-0 items-center justify-center rounded-lg border transition-colors"
        >
            <Icon className="size-4" />
        </Link>
    );
}

function AddItemButton({ t, onClick }: { t: TFn; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
        >
            <Plus className="size-4" /> {t('inventory.add_item')}
        </button>
    );
}

function EmptyState({ t, onAdd }: { t: TFn; onAdd: () => void }) {
    return (
        <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-14 text-center">
            <span className="bg-accent-subtle text-primary flex size-14 items-center justify-center rounded-2xl">
                <Package className="size-7" />
            </span>
            <h2 className="font-heading text-primary text-xl">{t('inventory.empty_title')}</h2>
            <p className="text-muted-foreground max-w-sm text-sm">{t('inventory.empty_body')}</p>
            <button
                type="button"
                onClick={onAdd}
                className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 mt-1 flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            >
                <Plus className="size-4" /> {t('inventory.add_item')}
            </button>
        </div>
    );
}
