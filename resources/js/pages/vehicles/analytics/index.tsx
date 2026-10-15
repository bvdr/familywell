import { Head, router } from '@inertiajs/react';
import { CircleAlert, Droplet, Fuel, Gauge, TrendingUp, Wrench } from 'lucide-react';
import { useReducedMotion } from 'motion/react';
import { type ReactNode } from 'react';

import { Bar, TrendCurve, useCountUp } from '@/components/charts';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import {
    consumptionText,
    costPerDistanceText,
    costPerKmText,
    costTypeColor,
    costTypeLabel,
    distanceText,
    distanceUnitLabel,
    maintStatusLabel,
    MAINT_STATUS_META,
    mileageText,
    vehicleTitle,
    type ConsumptionPoint,
    type FuelStats,
    type UnitSystem,
    type VehicleAnalyticsData,
    type VehicleEstimates,
    type VehicleHeader,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    unitSystem: UnitSystem;
    period: string;
    range: { from: string; to: string };
    analytics: VehicleAnalyticsData;
    fuel: FuelStats;
    consumptionTrend: ConsumptionPoint[];
    estimates: VehicleEstimates;
    canExpense: boolean;
};

const PERIODS = ['mtd', 'last_month', 'ytd', 'all_time', 'custom'] as const;

// Date label helpers, matching the Spending analytics screen so the two read identically.
const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
const monthDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
const fullDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

export default function VehicleAnalyticsPage({ vehicle, unitSystem, period, range, analytics, fuel, consumptionTrend, estimates }: Props) {
    const t = useT();
    const reduced = useReducedMotion() ?? false;
    const animatedTotal = useCountUp(analytics.total, reduced);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.costs'), href: `/m/vehicles/${vehicle.id}/analytics` },
    ];

    // Navigating reloads with new query params; Inertia keeps scroll and swaps the props (same as Spending).
    const reload = (params: Record<string, string>) =>
        router.get(`/m/vehicles/${vehicle.id}/analytics`, { period, from: range.from, to: range.to, ...params }, { preserveScroll: true, preserveState: true });

    const periodLabel: Record<string, string> = {
        mtd: t('vehicles.period_mtd'),
        last_month: t('vehicles.period_last_month'),
        ytd: t('vehicles.period_ytd'),
        all_time: t('vehicles.period_all_time'),
        custom: t('vehicles.period_custom'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.costs')}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage, unitSystem)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="analytics" />

                <div>
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.costs')}</h2>
                    <p className="text-muted-foreground text-sm">{t('vehicles.costs_sub')}</p>
                </div>

                {/* Period selector: the same five presets as Spending, as a segmented control. */}
                <div className="flex flex-wrap gap-2">
                    {PERIODS.map((p) => (
                        <button
                            key={p}
                            onClick={() => reload({ period: p })}
                            className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                period === p ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'
                            }`}
                        >
                            {periodLabel[p]}
                        </button>
                    ))}
                </div>

                {/* The concrete range the active period resolves to, so the numbers below are never ambiguous. */}
                {period !== 'custom' && (
                    <p className="text-muted-foreground -mt-2 text-xs">
                        {fullDate(range.from)} – {fullDate(range.to)}
                    </p>
                )}

                {period === 'custom' && (
                    <div className="-mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <input
                            type="date"
                            value={range.from}
                            max={range.to}
                            onChange={(e) => reload({ period: 'custom', from: e.target.value })}
                            className="border-border bg-surface-card text-primary rounded-lg border px-3 py-2"
                            aria-label={t('vehicles.from')}
                        />
                        <span className="text-muted-foreground">{t('vehicles.to')}</span>
                        <input
                            type="date"
                            value={range.to}
                            min={range.from}
                            onChange={(e) => reload({ period: 'custom', to: e.target.value })}
                            className="border-border bg-surface-card text-primary rounded-lg border px-3 py-2"
                            aria-label={t('vehicles.to')}
                        />
                    </div>
                )}

                {/* Headline row: total spent this period + the operating cost per km derived from it. */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                        <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.total_spent')}</span>
                        <span className="text-primary font-mono text-3xl font-semibold tabular-nums">{money(animatedTotal, analytics.currency)}</span>
                        <span className="text-muted-foreground text-xs">
                            {analytics.count} {t(analytics.count === 1 ? 'vehicles.expense_one' : 'vehicles.expense_other')} · {periodLabel[period]}
                        </span>
                    </div>
                    <div className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                        <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('vehicles.operating_cost', { unit: distanceUnitLabel(unitSystem) })}
                        </span>
                        <span className="text-primary font-mono text-3xl font-semibold tabular-nums">
                            {costPerKmText(analytics.costPerKm, analytics.currency, unitSystem)}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {analytics.kmDriven != null
                                ? t('vehicles.km_driven', { value: distanceText(analytics.kmDriven, unitSystem) })
                                : t('vehicles.km_driven_unknown')}
                        </span>
                    </div>
                </div>

                {/* Spending trend over the period — the shared smooth curve, per bucket (day / month). */}
                <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <h3 className="font-heading text-primary text-lg">{analytics.granularity === 'month' ? t('vehicles.by_month') : t('vehicles.by_day')}</h3>
                    <TrendCurve
                        points={analytics.daily.map((d) => ({ date: d.date, value: d.amount }))}
                        formatDate={analytics.granularity === 'month' ? monthDate : shortDate}
                        emptyLabel={t('vehicles.trend_empty')}
                        ariaLabel={t('vehicles.spend_chart_aria')}
                        renderPeak={(peak) => (
                            <span className="text-primary font-semibold">
                                {money(peak.value, analytics.currency)} {t('vehicles.peak')}
                            </span>
                        )}
                    />
                </div>

                {/* Spending by type — maintenance / repairs / fuel / parts / other, biggest first. */}
                <div className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <h3 className="font-heading text-primary text-lg">{t('vehicles.by_type')}</h3>
                    {analytics.byType.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-center text-sm">{t('vehicles.type_empty')}</p>
                    ) : (
                        analytics.byType.map((c, i) => (
                            <div key={c.key} className="flex flex-col gap-1.5">
                                <div className="flex items-baseline justify-between gap-3 text-sm">
                                    <span className="text-primary">{costTypeLabel(t, c.key)}</span>
                                    <span className="text-muted-foreground font-mono tabular-nums">
                                        {money(c.amount, analytics.currency)} · {c.pct}%
                                    </span>
                                </div>
                                <Bar pct={c.pct} color={costTypeColor(c.key)} index={i} />
                            </div>
                        ))
                    )}
                </div>

                {/* Fuel consumption — metrics + the consumption trend curve (efficiency over the car's life). */}
                <FuelConsumption fuel={fuel} trend={consumptionTrend} unitSystem={unitSystem} t={t} />

                {/* Estimations — clearly labelled projections, never presented as exact. */}
                <Estimates estimates={estimates} unitSystem={unitSystem} t={t} />
            </div>
        </AppLayout>
    );
}

// The fuel section: the headline consumption/cost metrics (from FuelStats) and, once there are at least two
// full-tank fills, the consumption trend curve. Hidden entirely until there's any fuel history.
function FuelConsumption({ fuel, trend, unitSystem, t }: { fuel: FuelStats; trend: ConsumptionPoint[]; unitSystem: UnitSystem; t: TFn }) {
    if (fuel.entryCount === 0) return null;

    const cells: { label: string; value: string }[] = [
        { label: t('vehicles.fuel_consumption_avg'), value: consumptionText(fuel.avgConsumption, unitSystem) },
        { label: t('vehicles.fuel_consumption_latest'), value: consumptionText(fuel.latestConsumption, unitSystem) },
        { label: t('vehicles.fuel_cost_per_km', { unit: distanceUnitLabel(unitSystem) }), value: costPerDistanceText(fuel.costPerKm, unitSystem) },
    ];

    return (
        <div className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
            <h3 className="font-heading text-primary flex items-center gap-2 text-lg">
                <Fuel className="size-5" /> {t('vehicles.fuel_consumption')}
            </h3>
            <div className="bg-border grid grid-cols-3 gap-px overflow-hidden rounded-xl border">
                {cells.map((c) => (
                    <div key={c.label} className="bg-surface-card flex flex-col gap-0.5 p-3">
                        <span className="text-muted-foreground text-[11px] font-semibold tracking-widest uppercase">{c.label}</span>
                        <span className="text-primary text-base font-semibold tabular-nums">{c.value}</span>
                    </div>
                ))}
            </div>
            {/* The trend needs two closed full-to-full segments to draw; below that it shows its empty note.
                The curve plots stored L/100km (its shape reads the same either way); the centre callout names
                the average in the family's unit rather than a "peak", since for mpg a higher line is worse. */}
            <TrendCurve
                points={trend}
                formatDate={shortDate}
                emptyLabel={t('vehicles.consumption_trend_empty')}
                ariaLabel={t('vehicles.consumption_chart_aria')}
                color="var(--accent-strong)"
                renderPeak={() => (
                    <span className="text-primary font-semibold">
                        {t('vehicles.fuel_consumption_avg')}: {consumptionText(fuel.avgConsumption, unitSystem)}
                    </span>
                )}
            />
        </div>
    );
}

// The estimations block. Two derived guesses, each with a clear "estimate" framing: the soonest service
// (from the maintenance due-logic) and the next fill (from the average interval between recorded fills).
function Estimates({ estimates, unitSystem, t }: { estimates: VehicleEstimates; unitSystem: UnitSystem; t: TFn }) {
    const { nextService, nextFuelFill } = estimates;
    if (!nextService && !nextFuelFill) return null;

    return (
        <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
            <div className="flex items-center gap-2">
                <TrendingUp className="text-muted-foreground size-5" />
                <h3 className="font-heading text-primary text-lg">{t('vehicles.estimates')}</h3>
            </div>
            {/* One clear caveat so nothing here reads as a promise. */}
            <p className="text-muted-foreground -mt-1 flex items-center gap-1.5 text-xs">
                <CircleAlert className="size-3.5 shrink-0" /> {t('vehicles.estimates_hint')}
            </p>

            <div className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-xl border">
                {nextService && (
                    <EstimateRow
                        icon={<Wrench className="size-4.5" />}
                        title={nextService.name}
                        detail={nextServiceDetail(nextService, unitSystem, t)}
                        tone={MAINT_STATUS_META[nextService.status]?.color}
                        badge={maintStatusLabel(t, nextService.status)}
                    />
                )}
                {nextFuelFill && (
                    <EstimateRow
                        icon={<Droplet className="size-4.5" />}
                        title={t('vehicles.est_next_fill')}
                        detail={
                            t('vehicles.est_fill_in', { days: nextFuelFill.days, date: fullDate(nextFuelFill.estimatedDate) }) +
                            (nextFuelFill.avgKmBetweenFills != null
                                ? ` · ${t('vehicles.est_avg_between', { value: distanceText(nextFuelFill.avgKmBetweenFills, unitSystem) })}`
                                : '')
                        }
                    />
                )}
            </div>
        </div>
    );
}

// Build the "~6,860 km / ~Mar 2027" style detail for the next-service estimate from whichever trigger is
// nearer. The leading "~" makes the estimate nature visible at a glance.
function nextServiceDetail(m: VehicleEstimates['nextService'], unit: UnitSystem, t: TFn): string {
    if (!m) return '';
    if (m.neverPerformed) return t('vehicles.never_performed');
    const parts: string[] = [];
    if (m.remainingKm != null && m.remainingKm >= 0) parts.push('~' + distanceText(m.remainingKm, unit));
    if (m.nextDueDate) parts.push('~' + fullDate(m.nextDueDate));
    if (parts.length === 0) return t('vehicles.due_now');
    return parts.join(' · ');
}

function EstimateRow({ icon, title, detail, tone, badge }: { icon: ReactNode; title: string; detail: string; tone?: string; badge?: string }) {
    return (
        <div className="bg-surface-card flex items-center gap-3 px-4 py-3">
            <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">{icon}</span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-primary truncate text-sm font-medium">{title}</span>
                    {badge && tone && (
                        <span className="flex shrink-0 items-center gap-1 text-xs font-medium" style={{ color: tone }}>
                            <span className="size-1.5 rounded-full" style={{ background: tone }} />
                            {badge}
                        </span>
                    )}
                </div>
                <span className="text-muted-foreground block truncate text-xs tabular-nums">{detail}</span>
            </div>
            <Gauge className="text-muted-foreground/50 size-4 shrink-0" />
        </div>
    );
}
