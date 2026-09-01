import { Bar, TrendCurve, useCountUp } from '@/components/charts';
import AppLayout from '@/layouts/app-layout';
import { catColor as catColorOf, catLabel as catLabelOf, categoryMap, type CategoryDef, type CategoryMap } from '@/lib/categories';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useReducedMotion } from 'motion/react';

type Cat = { key: string; amount: number; pct: number };
type Day = { date: string; amount: number };
// One individual expense in the drilled-down category (amounts already in display cents).
type Entry = { id: number; amount: number; note: string | null; category: string; date: string; list: string; list_id: number };
type Props = {
    currency: string;
    currencies: string[];
    categories: CategoryDef[];
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

// Category colour/label now come from the family's dynamic category list (passed as a prop), resolved
// through a key→def map so this page reads the same as the per-event stats page.
const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
// Monthly points read as "Jan 2026" — the day number is meaningless once buckets are whole months.
const monthDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });

// The per-day/-month spend curve: the shared smooth TrendCurve, fed the spend series and a peak callout in
// the display currency. Y is per-bucket spend (not cumulative), so the shape reads as the rhythm of spending.
function DailyCurve({ points, currency, granularity, t }: { points: Day[]; currency: string; granularity: 'day' | 'month'; t: TFn }) {
    // Monthly buckets get a month+year end label ("Jan 2026"); daily buckets keep "Jan 1".
    const label = granularity === 'month' ? monthDate : shortDate;
    return (
        <TrendCurve
            points={points.map((p) => ({ date: p.date, value: p.amount }))}
            formatDate={label}
            emptyLabel={t('spending.trend_empty')}
            ariaLabel={t('spending.daily_chart_aria')}
            renderPeak={(peak) => (
                <span className="text-primary font-semibold">
                    {money(peak.value, currency)} {t('spending.peak_day')}
                </span>
            )}
        />
    );
}

const PERIODS = ['mtd', 'last_month', 'ytd', 'all_time', 'custom'] as const;

// The resolved period always spans a concrete date range; show it so "this year" / "all time" / a custom
// range read unambiguously (a full date, with year, since all-time can reach back several years).
const fullDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

export default function Analytics({
    currency,
    currencies,
    categories,
    period,
    range,
    total,
    byCategory,
    daily,
    granularity,
    count,
    activeCategory,
    entries,
}: Props) {
    const t = useT();
    // Resolve a category key to its colour/label via the family's category list.
    const catMap: CategoryMap = categoryMap(categories);
    const catColor = (k: string) => catColorOf(catMap, k);
    const catLabel = (t: TFn, k: string) => catLabelOf(t, catMap, k);
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
        all_time: t('spending.period_all_time'),
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

                {/* The concrete range the active period resolves to, so the numbers below are never
                    ambiguous. Hidden for custom, which shows its own from/to inputs right underneath. */}
                {period !== 'custom' && (
                    <p className="text-muted-foreground -mt-1 text-xs">
                        {fullDate(range.from)} – {fullDate(range.to)}
                    </p>
                )}

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
