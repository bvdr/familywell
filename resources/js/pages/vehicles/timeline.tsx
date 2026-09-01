import { Head } from '@inertiajs/react';
import { FileText, Gauge, History, Receipt, Wrench, type LucideIcon } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { useLocale, useT, type TFn } from '@/lib/i18n';
import { distanceText, documentTypeLabel, mileageText, vehicleTitle, type TimelineEntry, type TimelineType, type UnitSystem, type Vehicle } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from './vehicle-nav';

type Props = {
    vehicle: Vehicle;
    unitSystem: UnitSystem;
    entries: TimelineEntry[];
};

// Icon + theme-token colour per event type. Colours track the active skin (never hard-coded hex).
const TYPE_META: Record<TimelineType, { icon: LucideIcon; color: string }> = {
    mileage: { icon: Gauge, color: 'var(--muted-foreground)' },
    maintenance: { icon: Wrench, color: 'var(--primary)' },
    repair: { icon: Wrench, color: 'var(--warning)' },
    document: { icon: FileText, color: 'var(--primary)' },
    expense: { icon: Receipt, color: 'var(--success)' },
};

// Local midnight for a Y-m-d string (avoids the UTC day-shift of new Date('2026-08-03')).
function parseDay(ymd: string): Date {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}

// Group the (already date-desc) entries into { monthKey, label, items }. The month label is localised.
function groupByMonth(entries: TimelineEntry[], locale: string): { key: string; label: string; items: TimelineEntry[] }[] {
    const groups: { key: string; label: string; items: TimelineEntry[] }[] = [];
    const byKey = new Map<string, { key: string; label: string; items: TimelineEntry[] }>();
    for (const e of entries) {
        // Dateless entries (shouldn't normally happen) fall into a single "—" bucket at the end.
        const key = e.date ? e.date.slice(0, 7) : '0000-00';
        let g = byKey.get(key);
        if (!g) {
            const label = e.date ? parseDay(e.date).toLocaleDateString(locale, { month: 'long', year: 'numeric' }) : '—';
            g = { key, label, items: [] };
            byKey.set(key, g);
            groups.push(g);
        }
        g.items.push(e);
    }
    return groups;
}

export default function VehicleTimeline({ vehicle, unitSystem, entries }: Props) {
    const t = useT();
    const locale = useLocale();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.tab_timeline'), href: `/m/vehicles/${vehicle.id}/timeline` },
    ];
    const groups = groupByMonth(entries, locale);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} — ${t('vehicles.tab_timeline')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex items-center gap-3">
                    <span className="bg-accent-subtle text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                        <History className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                        <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage, unitSystem)}</p>
                    </div>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="timeline" />

                {entries.length === 0 ? (
                    <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-12 text-center">
                        <History className="size-8 opacity-40" />
                        <p className="font-heading text-primary text-lg">{t('vehicles.timeline_empty_title')}</p>
                        <p className="text-sm">{t('vehicles.timeline_empty_body')}</p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-6">
                        {groups.map((g) => (
                            <section key={g.key} className="flex flex-col gap-3">
                                <h2 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{g.label}</h2>
                                {/* A single vertical rail down the left; each entry hangs a dot off it. */}
                                <ol className="border-border relative ml-3 flex flex-col gap-3 border-l pl-6">
                                    {g.items.map((e) => (
                                        <TimelineRow key={e.id} entry={e} unitSystem={unitSystem} t={t} locale={locale} />
                                    ))}
                                </ol>
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function TimelineRow({ entry, unitSystem, t, locale }: { entry: TimelineEntry; unitSystem: UnitSystem; t: TFn; locale: string }) {
    const meta = TYPE_META[entry.type] ?? TYPE_META.mileage;
    const Icon = meta.icon;

    // The headline: a real title for most types; the odometer reading itself for a bare mileage entry;
    // the type name as a last resort (e.g. a note-less expense).
    const title =
        entry.title || (entry.type === 'mileage' && entry.mileage != null ? mileageText(t, entry.mileage, unitSystem) : t(`vehicles.timeline_type_${entry.type}`));

    // Sub: document type label, or a provider, whichever the entry carries.
    const sub = entry.type === 'document' && entry.subtitle ? documentTypeLabel(t, entry.subtitle) : entry.subtitle;

    const day = entry.date ? parseDay(entry.date).toLocaleDateString(locale, { day: 'numeric', month: 'short' }) : '';

    return (
        <li className="relative">
            {/* The dot on the rail (pulled left to sit on the border line). */}
            <span
                className="border-surface-card absolute top-1 -left-[calc(1.5rem+1px)] flex size-6 -translate-x-1/2 items-center justify-center rounded-full border-2"
                style={{ background: 'var(--surface-card)' }}
            >
                <span className="flex size-6 items-center justify-center rounded-full" style={{ background: `color-mix(in srgb, ${meta.color} 15%, transparent)`, color: meta.color }}>
                    <Icon className="size-3.5" />
                </span>
            </span>

            <div className="bg-surface-card flex items-start gap-3 rounded-xl border px-3.5 py-2.5">
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-primary truncate text-sm font-medium">{title}</span>
                        <span className="text-muted-foreground shrink-0 text-[11px] font-medium tracking-wide uppercase" style={{ color: meta.color }}>
                            {t(`vehicles.timeline_type_${entry.type}`)}
                        </span>
                    </div>
                    <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                        {day && <span className="tabular-nums">{day}</span>}
                        {sub && <span className="truncate">· {sub}</span>}
                        {entry.type !== 'mileage' && entry.mileage != null && <span className="tabular-nums">· {distanceText(entry.mileage, unitSystem)}</span>}
                    </div>
                </div>
                {entry.cost != null && (
                    <span className="text-primary shrink-0 text-sm font-medium tabular-nums">{t('vehicles.cost_value', { value: entry.cost.toLocaleString('en-US') })}</span>
                )}
            </div>
        </li>
    );
}
