import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

import { CategoryTile, QtyBar, QtyText, StateBadge } from '@/components/inventory';
import { ItemFormModal } from '@/components/inventory-modals';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { useT, type TFn } from '@/lib/i18n';
import { byKey, catLabel, locLabel, stateLabel, type InvCategory, type InvItem, type InvLocation, type InvState } from '@/lib/inventory';
import { relativeTime } from '@/lib/notifications';
import type { BreadcrumbItem } from '@/types';

type Props = {
    items: InvItem[];
    categories: InvCategory[];
    locations: InvLocation[];
    shops: string[];
    statuses: InvState[];
};

// Read a query param once for the initial filter (e.g. arriving from a category browse card).
function initialParam(name: string): string {
    if (typeof window === 'undefined') return 'all';
    return new URLSearchParams(window.location.search).get(name) ?? 'all';
}

export default function InventoryItems({ items, categories, locations, shops, statuses }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const locMap = byKey(locations);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.all_items'), href: '/m/inventory/items' },
    ];

    const [status, setStatus] = useState<string>(initialParam('status'));
    const [category, setCategory] = useState<string>(initialParam('category'));
    const [location, setLocation] = useState<string>(initialParam('location'));
    const [adding, setAdding] = useState(openNewOnArrival);
    useAddAction(() => setAdding(true)); // the mobile nav "+" adds an item here

    const filtered = useMemo(
        () =>
            items.filter(
                (i) =>
                    (status === 'all' || i.state === status) &&
                    (category === 'all' || (i.category ?? '') === category) &&
                    (location === 'all' || (i.location ?? '') === location),
            ),
        [items, status, category, location],
    );

    // Group by category (the default view), category payload order first, then any leftover keys, with
    // uncategorised items last.
    const groups = useMemo(() => {
        const order = [...categories.map((c) => c.key)];
        const buckets = new Map<string, InvItem[]>();
        for (const item of filtered) {
            const k = item.category ?? '';
            if (!buckets.has(k)) buckets.set(k, []);
            buckets.get(k)!.push(item);
        }
        const keys = [...buckets.keys()].sort((a, b) => {
            if (a === '') return 1;
            if (b === '') return -1;
            const ia = order.indexOf(a);
            const ib = order.indexOf(b);
            return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
        });
        return keys.map((k) => ({ key: k, items: buckets.get(k)! }));
    }, [filtered, categories]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.all_items')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.all_items')}</h1>
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    >
                        <Plus className="size-4" /> {t('inventory.add_item')}
                    </button>
                </div>

                {/* Filters. Category/location options come from the family's own vocabularies. */}
                <div className="flex flex-wrap gap-2">
                    <Filter
                        label={t('inventory.filter_status')}
                        value={status}
                        onChange={setStatus}
                        options={[{ value: 'all', label: t('inventory.filter_all') }, ...statuses.map((s) => ({ value: s, label: stateLabel(t, s) }))]}
                    />
                    <Filter
                        label={t('inventory.filter_category')}
                        value={category}
                        onChange={setCategory}
                        options={[{ value: 'all', label: t('inventory.filter_all') }, ...categories.map((c) => ({ value: c.key, label: catLabel(t, c.key, catMap) }))]}
                    />
                    <Filter
                        label={t('inventory.filter_location')}
                        value={location}
                        onChange={setLocation}
                        options={[{ value: 'all', label: t('inventory.filter_all') }, ...locations.map((l) => ({ value: l.key, label: locLabel(t, l.key, locMap) }))]}
                    />
                </div>

                {items.length === 0 ? (
                    <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-10 text-center text-sm">{t('inventory.empty_body')}</p>
                ) : filtered.length === 0 ? (
                    <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-10 text-center text-sm">{t('inventory.no_matches')}</p>
                ) : (
                    <div className="flex flex-col gap-5">
                        {groups.map((g) => (
                            <section key={g.key || 'uncategorised'} className="flex flex-col gap-2">
                                <div className="flex items-center gap-2 px-1">
                                    <CategoryTile category={catMap[g.key]} size="sm" />
                                    <h2 className="font-heading text-primary text-base">{catLabel(t, g.key || null, catMap)}</h2>
                                    <span className="text-muted-foreground text-xs">{g.items.length}</span>
                                </div>
                                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                                    {g.items.map((item) => (
                                        <li key={item.id}>
                                            <ItemRow item={item} locMap={locMap} t={t} />
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </div>

            <ItemFormModal
                open={adding}
                onOpenChange={setAdding}
                categories={categories}
                locations={locations}
                shops={shops}
                names={items.map((i) => i.name)}
            />
        </AppLayout>
    );
}

function ItemRow({ item, locMap, t }: { item: InvItem; locMap: Record<string, InvLocation>; t: TFn }) {
    const secondary = [item.location ? locLabel(t, item.location, locMap) : null, item.updatedAt ? relativeTime(item.updatedAt) : null]
        .filter(Boolean)
        .join(' · ');
    return (
        <Link href={`/m/inventory/items/${item.id}`} className="bg-surface-card flex items-center gap-3 px-4 py-3">
            <span className="min-w-0 flex-1">
                <span className="text-primary block truncate text-[15px]">{item.name}</span>
                {secondary && <span className="text-muted-foreground block truncate text-xs">{secondary}</span>}
                <span className="mt-1.5 block max-w-[220px]">
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
    );
}

// A labelled native select styled to match the app's controls.
function Filter({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[] }) {
    return (
        <label className="flex items-center gap-1.5">
            <span className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="border-border text-primary h-9 rounded-lg border bg-transparent px-2 text-sm"
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
