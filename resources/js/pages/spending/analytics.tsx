import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { animate, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';

type Cat = { key: string; amount: number; pct: number };
type Day = { date: string; amount: number };
// One individual expense in the drilled-down category (amounts already in display cents).
type Entry = { id: number; amount: number; note: string | null; category: string; date: string; list: string; list_id: number };
type Props = {
    currency: string;
    currencies: string[];
    period: string;
    range: { from: string; to: string };
    total: number;
    byCategory: Cat[];
    daily: Day[];
    granularity: 'day' | 'month';
    count: number;
    activeCategory: string | null;
    entries: Entry[];
};

// Same earthy category palette + label keys as the per-event stats page, so the two views read alike.
const CATEGORY_META: Record<string, { labelKey: string; color: string }> = {
    food: { labelKey: 'spending.category_food', color: '#B5623C' },
    transport: { labelKey: 'spending.category_transport', color: '#2F6B44' },
    lodging: { labelKey: 'spending.category_lodging', color: '#C99A3A' },
    activities: { labelKey: 'spending.category_activities', color: '#4F7A6B' },
    other: { labelKey: 'spending.category_other', color: '#9A8C7A' },
};
const catColor = (k: string) => CATEGORY_META[k]?.color ?? '#9A8C7A';
const catLabel = (t: TFn, k: string) => (CATEGORY_META[k] ? t(CATEGORY_META[k].labelKey) : k.charAt(0).toUpperCase() + k.slice(1));

const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
// Monthly points read as "Jan 2026" — the day number is meaningless once buckets are whole months.
const monthDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });

// A smooth (rounded) line through the daily points, built as a Catmull-Rom spline converted to cubic
// beziers. Straight L-segments would look jagged; this gives the rounded curve the design asks for
// without pulling in a chart library. Y is per-day spend (not cumulative), so the shape reads as the
// rhythm of spending across the selected period.
function DailyCurve({ points, currency, granularity, t }: { points: Day[]; currency: string; granularity: 'day' | 'month'; t: TFn }) {
    // Hook must run before any early return so it stays unconditional. Under reduced motion we snap.
    const reduced = useReducedMotion();
    if (points.length < 2 || points.every((p) => p.amount === 0)) {
        return <p className="text-muted-foreground py-10 text-center text-sm">{t('spending.trend_empty')}</p>;
    }
    const w = 600;
    const h = 190;
    const pad = 12;
    const max = Math.max(...points.map((p) => p.amount)) || 1;
    const x = (i: number) => pad + (i * (w - pad * 2)) / (points.length - 1);
    const y = (v: number) => h - pad - (v / max) * (h - pad * 2);

    // Catmull-Rom -> bezier: each segment's control points are derived from the neighbouring samples,
    // giving a curve that passes through every point but bends smoothly between them.
    const pts = points.map((p, i) => ({ x: x(i), y: y(p.amount) }));
    let d = `M${pts[0].x.toFixed(1)},${pts[0].y.toFixed(1)}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        const c1x = p1.x + (p2.x - p0.x) / 6;
        const c1y = p1.y + (p2.y - p0.y) / 6;
        const c2x = p2.x - (p3.x - p1.x) / 6;
        const c2y = p2.y - (p3.y - p1.y) / 6;
        d += ` C${c1x.toFixed(1)},${c1y.toFixed(1)} ${c2x.toFixed(1)},${c2y.toFixed(1)} ${p2.x.toFixed(1)},${p2.y.toFixed(1)}`;
    }
    const area = `${d} L${x(points.length - 1).toFixed(1)},${h - pad} L${x(0).toFixed(1)},${h - pad} Z`;
    const peak = points.reduce((m, p) => (p.amount > m.amount ? p : m), points[0]);
    // Monthly buckets get a month+year end label ("Jan 2026"); daily buckets keep "Jan 1".
    const label = granularity === 'month' ? monthDate : shortDate;

    return (
        <div className="flex flex-col gap-2">
            <svg viewBox={`0 0 ${w} ${h}`} className="h-48 w-full" role="img" aria-label={t('spending.daily_chart_aria')}>
                <defs>
                    <linearGradient id="fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--primary)" stopOpacity="0.22" />
                        <stop offset="100%" stopColor="var(--primary)" stopOpacity="0" />
                    </linearGradient>
                </defs>
                {/* Area fill fades and scales up from its base under the line. transform-box: fill-box so
                    the scaleY origin is the element's own bottom edge, not the SVG root. Keyed by `area` so a
                    period/data change remounts it and the intro replays. */}
                <motion.path
                    key={`area-${area}`}
                    d={area}
                    fill="url(#fill)"
                    style={{ transformBox: 'fill-box', transformOrigin: 'bottom' }}
                    initial={reduced ? false : { opacity: 0, scaleY: 0.85 }}
                    animate={{ opacity: 1, scaleY: 1 }}
                    transition={{ duration: 0.6, ease: 'easeOut', delay: 0.12 }}
                />
                {/* Line draws itself on via pathLength 0 -> 1 (motion maps this to stroke-dash under the hood).
                    Keyed by `d` so it re-draws when the period or data changes. */}
                <motion.path
                    key={`line-${d}`}
                    d={d}
                    fill="none"
                    stroke="var(--primary)"
                    strokeWidth="2.5"
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    initial={reduced ? false : { pathLength: 0 }}
                    animate={{ pathLength: 1 }}
                    transition={{ duration: 0.8, ease: 'easeOut' }}
                />
            </svg>
            <div className="text-muted-foreground flex justify-between px-1 font-mono text-xs tabular-nums">
                <span>{label(points[0].date)}</span>
                <span className="text-primary font-semibold">
                    {money(peak.amount, currency)} {t('spending.peak_day')}
                </span>
                <span>{label(points[points.length - 1].date)}</span>
            </div>
        </div>
    );
}

function Bar({ pct, color, index }: { pct: number; color: string; index: number }) {
    const reduced = useReducedMotion();
    return (
        <div className="bg-surface h-2.5 overflow-hidden rounded-full">
            {/* Grow width in with a small per-row delay for a subtle stagger; reduced motion snaps to full. */}
            <motion.div
                className="h-full rounded-full"
                style={{ background: color }}
                initial={reduced ? false : { width: '0%' }}
                animate={{ width: `${pct}%` }}
                transition={{ duration: 0.7, ease: 'easeOut', delay: reduced ? 0 : 0.05 * index }}
            />
        </div>
    );
}

// Count a formatted money value up from 0 on mount and whenever the target changes. money() wants integer
// cents, so we round each animated frame before formatting. Reduced motion skips straight to the value.
function useCountUp(target: number, reduced: boolean) {
    const [value, setValue] = useState(reduced ? target : 0);
    useEffect(() => {
        if (reduced) {
            setValue(target);
            return;
        }
        const controls = animate(0, target, {
            duration: 0.7,
            ease: 'easeOut',
            onUpdate: (v) => setValue(Math.round(v)),
        });
        return () => controls.stop();
    }, [target, reduced]);
    return value;
}

const PERIODS = ['mtd', 'last_month', 'ytd', 'custom'] as const;

export default function Analytics({ currency, currencies, period, range, total, byCategory, daily, granularity, count, activeCategory, entries }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.spending'), href: '/m/spending' },
        { title: t('spending.analytics'), href: route('spending.analytics') },
    ];

    const reduced = useReducedMotion() ?? false;
    const animatedTotal = useCountUp(total, reduced);

    // Navigating reloads the page with new query params; Inertia keeps scroll and swaps the props.
    // The active category rides along so switching period/currency keeps the drill-down filter.
    const reload = (params: Record<string, string>) =>
        router.get(
            route('spending.analytics'),
            { period, currency, from: range.from, to: range.to, category: activeCategory ?? '', ...params },
            { preserveScroll: true, preserveState: true },
        );

    // Tapping a category filters to it; tapping the active one again clears back to all.
    const toggleCategory = (key: string) => reload({ category: key === activeCategory ? '' : key });

    const periodLabel: Record<string, string> = {
        mtd: t('spending.period_mtd'),
        last_month: t('spending.period_last_month'),
        ytd: t('spending.period_ytd'),
        custom: t('spending.period_custom'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('spending.analytics')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">{t('spending.analytics')}</h1>
                        <p className="text-muted-foreground text-sm">{t('spending.analytics_sub')}</p>
                    </div>
                    {/* Native select for the display currency: mixed-base lists need a single currency to roll up in. */}
                    <select
                        value={currency}
                        onChange={(e) => reload({ currency: e.target.value })}
                        className="border-border bg-surface-card text-primary rounded-lg border px-3 py-2 text-sm"
                        aria-label={t('spending.display_currency')}
                    >
                        {currencies.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </select>
                </div>

                {/* Period selector: four presets as a segmented control. */}
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

                {/* Custom range: native date inputs (rung 4 — the platform control, no picker library). */}
                {period === 'custom' && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <input
                            type="date"
                            value={range.from}
                            max={range.to}
                            onChange={(e) => reload({ period: 'custom', from: e.target.value })}
                            className="border-border bg-surface-card text-primary rounded-lg border px-3 py-2"
                            aria-label={t('spending.from')}
                        />
                        <span className="text-muted-foreground">{t('spending.to')}</span>
                        <input
                            type="date"
                            value={range.to}
                            min={range.from}
                            onChange={(e) => reload({ period: 'custom', to: e.target.value })}
                            className="border-border bg-surface-card text-primary rounded-lg border px-3 py-2"
                            aria-label={t('spending.to')}
                        />
                    </div>
                )}

                {/* Total spent for the selected period. */}
                <div className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.total_spent')}</span>
                    <span className="text-primary font-mono text-3xl font-semibold tabular-nums">{money(animatedTotal, currency)}</span>
                    <span className="text-muted-foreground text-xs">
                        {count} {t(count === 1 ? 'spending.spend_one' : 'spending.spend_other')} · {periodLabel[period]}
                    </span>
                </div>

                {/* Expenses by day, rounded curve. */}
                <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <h2 className="font-heading text-primary text-lg">{granularity === 'month' ? t('spending.by_month') : t('spending.by_day')}</h2>
                    <DailyCurve points={daily} currency={currency} granularity={granularity} t={t} />
                </div>

                {/* Spending by category — each row is a filter. Tapping one drills the whole view (total,
                    count, chart, and the entries list below) into that category; tapping it again clears. */}
                <div className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <h2 className="font-heading text-primary text-lg">{t('spending.by_category')}</h2>
                    {byCategory.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-center text-sm">{t('spending.category_empty')}</p>
                    ) : (
                        byCategory.map((c, i) => {
                            const active = c.key === activeCategory;
                            return (
                                <button
                                    key={c.key}
                                    type="button"
                                    onClick={() => toggleCategory(c.key)}
                                    aria-pressed={active}
                                    className={`-mx-2 flex flex-col gap-1.5 rounded-lg px-2 py-1.5 text-left transition-colors ${
                                        active ? 'bg-surface ring-primary/40 ring-1' : 'hover:bg-surface'
                                    }`}
                                >
                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className={active ? 'text-primary font-semibold' : 'text-primary'}>{catLabel(t, c.key)}</span>
                                        <span className="text-muted-foreground font-mono tabular-nums">
                                            {money(c.amount, currency)} · {c.pct}%
                                        </span>
                                    </div>
                                    <Bar pct={c.pct} color={catColor(c.key)} index={i} />
                                </button>
                            );
                        })
                    )}
                    {activeCategory && (
                        <button type="button" onClick={() => toggleCategory(activeCategory)} className="text-muted-foreground self-start text-xs underline">
                            {t('spending.all_categories')}
                        </button>
                    )}
                </div>

                {/* Drill-down: every individual expense in the filtered category, biggest first. */}
                {activeCategory && (
                    <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                        <h2 className="font-heading text-primary text-lg">
                            {t('spending.entries_title')} · {catLabel(t, activeCategory)}
                        </h2>
                        {entries.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center text-sm">{t('spending.category_empty')}</p>
                        ) : (
                            <ul className="flex flex-col divide-y">
                                {entries.map((e) => (
                                    <li key={e.id}>
                                        {/* Whole row links to its parent list's detail page. -mx-2/px-2 gives the
                                            pressable target a subtle hover/active tint without breaking the divider. */}
                                        <Link
                                            href={route('spending.show', e.list_id)}
                                            className="hover:bg-surface active:bg-surface -mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors"
                                        >
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <span className="size-2.5 shrink-0 rounded-full" style={{ background: catColor(e.category) }} />
                                                <div className="min-w-0">
                                                    <div className="text-muted-foreground truncate text-xs">
                                                        {e.list} · {shortDate(e.date)}
                                                    </div>
                                                    <div className="text-primary truncate text-sm">{e.note || catLabel(t, e.category)}</div>
                                                </div>
                                            </div>
                                            <span className="text-primary shrink-0 font-mono text-sm tabular-nums">{money(e.amount, currency)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
