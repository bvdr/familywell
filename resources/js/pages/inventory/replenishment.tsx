import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CheckCircle2, ShoppingCart } from 'lucide-react';
import { useState } from 'react';

import { AddToShoppingButton, CategoryTile, QtyText, StateBadge } from '@/components/inventory';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { byKey, fmtNumber, unitLabel, type InvCategory, type InvItem, type InvLocation } from '@/lib/inventory';
import type { BreadcrumbItem } from '@/types';

// A replenishment row is an item plus the amount a purchase would top it up to (server-derived).
type RepItem = InvItem & { replenishTo: number };

type Props = {
    items: RepItem[];
    pending: number; // how many are not yet on a list — what "Add all" would actually add
    categories: InvCategory[];
    locations: InvLocation[];
};

export default function InventoryReplenishment({ items, pending, categories }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const [addingAll, setAddingAll] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.replenishment_title'), href: '/m/inventory/replenishment' },
    ];

    const addAll = () =>
        router.post(route('inventory.shopping.add-low'), {}, { preserveScroll: true, onStart: () => setAddingAll(true), onFinish: () => setAddingAll(false) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.replenishment_title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <Link href="/m/inventory" className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <ArrowLeft className="size-4" /> {t('inventory.title')}
                </Link>

                <header>
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.replenishment_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('inventory.replenishment_subtitle', { count: items.length })}</p>
                </header>

                {items.length === 0 ? (
                    <AllStocked t={t} />
                ) : (
                    <>
                        {/* Add every remaining item to Shopping at once. Hidden once they're all already on a list. */}
                        {pending > 0 && (
                            <button
                                type="button"
                                onClick={addAll}
                                disabled={addingAll}
                                className="bg-accent-strong text-accent-strong-foreground flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-medium transition-opacity disabled:opacity-50"
                            >
                                <ShoppingCart className="size-4" /> {t('inventory.add_all_to_shopping', { count: pending })}
                            </button>
                        )}

                        <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                            {items.map((item) => (
                                <li key={item.id} className="bg-surface-card flex items-center gap-3 px-4 py-3">
                                    <Link href={`/m/inventory/items/${item.id}`} className="flex min-w-0 flex-1 items-center gap-3">
                                        <CategoryTile category={catMap[item.category ?? '']} size="sm" />
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-[15px]">{item.name}</span>
                                            {/* qty -> target: what's left now and where a buy would top it up to. */}
                                            <span className="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs tabular-nums">
                                                <QtyText item={item} />
                                                <ArrowRight className="size-3" />
                                                <span className="text-primary">
                                                    {fmtNumber(item.replenishTo)} {unitLabel(t, item.unit)}
                                                </span>
                                            </span>
                                        </span>
                                        <StateBadge state={item.state} className="shrink-0" />
                                    </Link>
                                    <AddToShoppingButton item={item} />
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function AllStocked({ t }: { t: TFn }) {
    return (
        <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-14 text-center">
            <span className="flex size-14 items-center justify-center rounded-2xl text-[var(--success)]" style={{ background: 'color-mix(in srgb, var(--success) 14%, transparent)' }}>
                <CheckCircle2 className="size-7" />
            </span>
            <h2 className="font-heading text-primary text-xl">{t('inventory.replenishment_empty_title')}</h2>
            <p className="text-muted-foreground max-w-sm text-sm">{t('inventory.replenishment_empty_body')}</p>
        </div>
    );
}
