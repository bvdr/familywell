import { Bar, TrendCurve, useCountUp } from '@/components/charts';
import { Picker } from '@/components/picker';
import AppLayout from '@/layouts/app-layout';
import { catColor as catColorOf, catLabel as catLabelOf, categoryMap, type CategoryDef, type CategoryMap } from '@/lib/categories';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { useReducedMotion } from 'motion/react';

type Cat = { key: string; amount: number; pct: number };
type Day = { date: string; amount: number };
// One individual expense in the drilled-down category (amounts already in display cents).
type Entry = { id: number; amount: number; note: string | null; category: string; store: string | null; date: string; list: string; list_id: number };
// byShop row: key is the store name, or NO_SHOP for spends without one.
type Shop = { key: string; amount: number; pct: number };
const NO_SHOP = '__none'; // mirrors SpendingController::NO_SHOP
type Props = {
    currency: string;
    currencies: string[];
    categories: CategoryDef[];
    period: string;
    range: { from: string; to: string };
    total: number;
    byCategory: Cat[];
    byShop: Shop[];
    daily: Day[];
    granularity: 'day' | 'week' | 'month';
    count: number;
    activeCategory: string | null;
    activeShop: string | null;
    entries: Entry[];
};
type Granularity = Props['granularity'];

// Category colour/label now come from the family's dynamic category list (passed as a prop), resolved
// through a key→def map so this page reads the same as the per-event stats page.
const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
// Monthly points read as "Jan 2026" — the day number is meaningless once buckets are whole months.
const monthDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });

// The per-day/-month spend curve: the shared smooth TrendCurve, fed the spend series and a peak callout in
// the display currency. Y is per-bucket spend (not cumulative), so the shape reads as the rhythm of spending.
function DailyCurve({ points, currency, granularity, t }: { points: Day[]; currency: string; granularity: Granularity; t: TFn }) {
    // Monthly buckets get a month+year end label ("Jan 2026"); weekly buckets read as the Monday they start
    // on ("Week of Sep 21"); daily buckets keep "Jan 1".
    const label =
        granularity === 'month' ? monthDate : granularity === 'week' ? (d: string) => t('spending.week_of', { date: shortDate(d) }) : shortDate;
    const peakKey = { day: 'spending.peak_day', week: 'spending.peak_week', month: 'spending.peak_month' }[granularity];
    return (
        <TrendCurve
            points={points.map((p) => ({ date: p.date, value: p.amount }))}
            formatDate={label}
            emptyLabel={t('spending.trend_empty')}
            ariaLabel={t('spending.daily_chart_aria')}
            renderPeak={(peak) => (
                <span className="text-primary font-semibold">
                    {money(peak.value, currency)} {t(peakKey)}
                </span>
            )}
        />
    );
}

const PERIODS = ['this_week', 'last_week', 'mtd', 'last_month', 'ytd', 'all_time', 'custom'] as const;
// Chart heading per granularity.
const BY_KEY: Record<Granularity, string> = { day: 'spending.by_day', week: 'spending.by_week', month: 'spending.by_month' };

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
    byShop,
    daily,
    granularity,
    count,
    activeCategory,
    activeShop,
    entries,
}: Props) {
    const t = useT();
    // Resolve a category key to its colour/label via the family's category list.
    const catMap: CategoryMap = categoryMap(categories);
    const catColor = (k: string) => catColorOf(catMap, k);
    const catLabel = (t: TFn, k: string) => catLabelOf(t, catMap, k);
    const shopLabel = (k: string) => (k === NO_SHOP ? t('spending.no_shop') : k);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.spending'), href: '/m/spending' },
        { title: t('spending.analytics'), href: route('spending.analytics') },
    ];

    const reduced = useReducedMotion() ?? false;
    const animatedTotal = useCountUp(total, reduced);

    // Navigating reloads the page with new query params; Inertia keeps scroll and swaps the props.
    // The active category/shop ride along so switching period/currency keeps the drill-down filters.
    const reload = (params: Record<string, string>) =>
        router.get(
            route('spending.analytics'),
            { period, currency, from: range.from, to: range.to, category: activeCategory ?? '', shop: activeShop ?? '', ...params },
            { preserveScroll: true, preserveState: true },
        );

    // Tapping a category/shop filters to it; tapping the active one again clears back to all.
    const toggleCategory = (key: string) => reload({ category: key === activeCategory ? '' : key });
    const toggleShop = (key: string) => reload({ shop: key === activeShop ? '' : key });
    const filtering = activeCategory !== null || activeShop !== null;
    // Drill-down heading: "Expenses · Food · Lidl" (whichever filters are on).
    const filterTitle = [activeCategory && catLabel(t, activeCategory), activeShop && shopLabel(activeShop)].filter(Boolean).join(' · ');

    const periodLabel: Record<string, string> = {
        this_week: t('spending.period_this_week'),
        last_week: t('spending.period_last_week'),
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
                    {/* Display currency: mixed-base lists need a single currency to roll up in. */}
                    <div className="w-[120px] shrink-0 self-start">
                        <Picker
                            options={currencies.map((c) => ({ value: c, label: c }))}
                            selected={[currency]}
                            onChange={([c]) => c && c !== currency && reload({ currency: c })}
                            fallbackIcon={Banknote}
                            ariaLabel={t('spending.display_currency')}
                        />
                    </div>
                </div>

                {/* Period selector: presets as a segmented control. */}
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

                {/* Expenses by day / week / month, rounded curve. */}
                <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <h2 className="font-heading text-primary text-lg">{t(BY_KEY[granularity])}</h2>
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
                        <button
                            type="button"
                            onClick={() => toggleCategory(activeCategory)}
                            className="text-muted-foreground self-start text-xs underline"
                        >
                            {t('spending.all_categories')}
                        </button>
                    )}
                </div>

                {/* Spending by shop — same interaction as categories: tap a shop to filter the whole view to it
                    (combines with the category filter), tap again to clear. Spends without a shop sit in a
                    "No shop" row so the rows still add up to the total. */}
                <div className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <h2 className="font-heading text-primary text-lg">{t('spending.by_shop')}</h2>
                    {byShop.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-center text-sm">{t('spending.shop_empty')}</p>
                    ) : (
                        byShop.map((s, i) => {
                            const active = s.key === activeShop;
                            return (
                                <button
                                    key={s.key}
                                    type="button"
                                    onClick={() => toggleShop(s.key)}
                                    aria-pressed={active}
                                    className={`-mx-2 flex flex-col gap-1.5 rounded-lg px-2 py-1.5 text-left transition-colors ${
                                        active ? 'bg-surface ring-primary/40 ring-1' : 'hover:bg-surface'
                                    }`}
                                >
                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                        <span
                                            className={`truncate ${active ? 'text-primary font-semibold' : s.key === NO_SHOP ? 'text-muted-foreground' : 'text-primary'}`}
                                        >
                                            {shopLabel(s.key)}
                                        </span>
                                        <span className="text-muted-foreground shrink-0 font-mono tabular-nums">
                                            {money(s.amount, currency)} · {s.pct}%
                                        </span>
                                    </div>
                                    {/* Shops have no colour of their own; the muted bar for "No shop" sets it apart. */}
                                    <Bar pct={s.pct} color={s.key === NO_SHOP ? 'var(--muted-foreground)' : 'var(--primary)'} index={i} />
                                </button>
                            );
                        })
                    )}
                    {activeShop && (
                        <button type="button" onClick={() => toggleShop(activeShop)} className="text-muted-foreground self-start text-xs underline">
                            {t('spending.all_shops')}
                        </button>
                    )}
                </div>

                {/* Drill-down: every individual expense matching the active category/shop, biggest first. */}
                {filtering && (
                    <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                        <h2 className="font-heading text-primary text-lg">
                            {t('spending.entries_title')} · {filterTitle}
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
                                                        {e.store ? ` · ${e.store}` : ''}
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
