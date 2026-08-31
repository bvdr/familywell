import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, Clock, PackageX, ShoppingCart, TrendingDown } from 'lucide-react';

import { AddToShoppingButton, CategoryTile, QtyBar, QtyText, StateBadge } from '@/components/inventory';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { byKey, type InvCategory, type InvItem, type InvLocation } from '@/lib/inventory';
import type { BreadcrumbItem } from '@/types';

// STALE_AFTER_DAYS on the server (InventoryItem::STALE_AFTER_DAYS). Mirrored here only for the section's
// helper copy ("untouched for over N days"); the actual staleness is decided server-side.
const STALE_AFTER_DAYS = 45;

type Props = {
    empty: InvItem[];
    low: InvItem[];
    expiring: InvItem[];
    stale: InvItem[];
    total: number;
    categories: InvCategory[];
    locations: InvLocation[];
};

export default function InventoryNeedsAttention({ empty, low, expiring, stale, total, categories }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.needs_attention_title'), href: '/m/inventory/needs-attention' },
    ];

    // The bucket sections in triage order (most urgent first). `hint` is optional sub-copy (stale only).
    const sections = [
        { key: 'empty', icon: PackageX, title: t('inventory.section_empty'), items: empty },
        { key: 'low', icon: TrendingDown, title: t('inventory.section_low'), items: low },
        { key: 'expiring', icon: Clock, title: t('inventory.section_expiring'), items: expiring },
        {
            key: 'stale',
            icon: AlertTriangle,
            title: t('inventory.section_stale'),
            items: stale,
            hint: t('inventory.section_stale_hint', { days: STALE_AFTER_DAYS }),
        },
    ].filter((s) => s.items.length > 0);

    // Any low OR empty item is a replenishment candidate; the bulk button offers them all at once (P4).
    const bulkCount = low.length + empty.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.needs_attention_title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <Link href="/m/inventory" className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <ArrowLeft className="size-4" /> {t('inventory.title')}
                </Link>

                <header>
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.needs_attention_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('inventory.needs_attention_subtitle', { count: total })}</p>
                </header>

                {total === 0 ? (
                    <AllGood t={t} />
                ) : (
                    <>
                        {/* Bulk replenishment. Inert until Phase 4 wires the Shopping loop. */}
                        {bulkCount > 0 && (
                            <button
                                type="button"
                                disabled
                                title={t('inventory.coming_soon')}
                                className="bg-accent-strong text-accent-strong-foreground flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-medium opacity-50"
                            >
                                <ShoppingCart className="size-4" /> {t('inventory.add_all_low_to_shopping')}
                            </button>
                        )}

                        {sections.map((s) => (
                            <section key={s.key} className="flex flex-col gap-2">
                                <div className="flex items-center gap-2 px-1">
                                    <s.icon className="text-muted-foreground size-4" />
                                    <h2 className="font-heading text-primary text-lg">{s.title}</h2>
                                    <span className="text-muted-foreground text-xs tabular-nums">{s.items.length}</span>
                                </div>
                                {s.hint && <p className="text-muted-foreground -mt-1 px-1 text-xs">{s.hint}</p>}
                                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                                    {s.items.map((item) => (
                                        <li key={item.id} className="bg-surface-card flex items-center gap-3 px-4 py-3">
                                            <Link href={`/m/inventory/items/${item.id}`} className="flex min-w-0 flex-1 items-center gap-3">
                                                <CategoryTile category={catMap[item.category ?? '']} size="sm" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-primary block truncate text-[15px]">{item.name}</span>
                                                    <span className="mt-1 block max-w-[220px]">
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
                                            <AddToShoppingButton compact />
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function AllGood({ t }: { t: TFn }) {
    return (
        <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-14 text-center">
            <span className="flex size-14 items-center justify-center rounded-2xl text-[var(--success)]" style={{ background: 'color-mix(in srgb, var(--success) 14%, transparent)' }}>
                <CheckCircle2 className="size-7" />
            </span>
            <h2 className="font-heading text-primary text-xl">{t('inventory.all_good_title')}</h2>
            <p className="text-muted-foreground max-w-sm text-sm">{t('inventory.all_good_body')}</p>
        </div>
    );
}
