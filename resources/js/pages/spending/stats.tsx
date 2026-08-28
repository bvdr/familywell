import AppLayout from '@/layouts/app-layout';
import { catColor as catColorOf, catLabel as catLabelOf, categoryMap, type CategoryDef, type CategoryMap } from '@/lib/categories';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Cat = { key: string; amount: number; pct: number };
type Source = { key: string; label: string; type: string; amount: number; pct: number };
type Point = { date: string; amount: number; cumulative: number };
type Props = {
    event: { id: number; name: string; currency: string; total: number };
    categories: CategoryDef[];
    byCategory: Cat[];
    bySource: Source[];
    overTime: Point[];
    facts: {
        count: number;
        average: number;
        dailyAverage: number;
        biggest: { amount: number; label: string } | null;
        days: number;
        people: number;
        perPerson: number;
    };
};

// Category colour/label come from the family's dynamic category list (a prop); the same earthy palette
// as before ships as the seeded defaults. Resolved via a key→def map built in the component.

// Payment methods share the same earthy palette, keyed by source type; unspecified is the neutral taupe.
const SOURCE_COLOR: Record<string, string> = {
    cash: '#2F6B44', // forest
    card: '#B5623C', // terracotta
    bank: '#C99A3A', // gold
    other: '#4F7A6B', // muted teal-green
    none: '#9A8C7A', // taupe
};
// Same source-type vocabulary as the "paid with" picker on the event page (lang/*/spending.json).
const SOURCE_TYPE_LABEL_KEY: Record<string, string> = {
    cash: 'spending.source_cash',
    card: 'spending.source_card',
    bank: 'spending.source_bank',
    other: 'spending.source_other',
};
const sourceTypeLabel = (t: TFn, type: string) => (SOURCE_TYPE_LABEL_KEY[type] ? t(SOURCE_TYPE_LABEL_KEY[type]) : type);

const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

// A bar whose fill grows from 0 to its share on mount (snaps instantly under reduced motion).
function Bar({ pct, color, grown }: { pct: number; color: string; grown: boolean }) {
    return (
        <div className="bg-surface h-2.5 overflow-hidden rounded-full">
            <div
                className="h-full rounded-full transition-[width] duration-700 ease-out motion-reduce:transition-none"
                style={{ width: grown ? `${pct}%` : '0%', background: color }}
            />
        </div>
    );
}

// Cumulative-spend curve, drawn by hand so we add no chart dependency.
function TrendCurve({ points, currency, t }: { points: Point[]; currency: string; t: TFn }) {
    if (points.length < 2) {
        return <p className="text-muted-foreground py-6 text-center text-sm">{t('spending.trend_empty')}</p>;
    }
    const w = 600;
    const h = 170;
    const pad = 10;
    const max = Math.max(...points.map((p) => p.cumulative)) || 1;
    const x = (i: number) => pad + (i * (w - pad * 2)) / (points.length - 1);
    const y = (v: number) => h - pad - (v / max) * (h - pad * 2);
    const line = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.cumulative).toFixed(1)}`).join(' ');
    const area = `${line} L${x(points.length - 1).toFixed(1)},${h - pad} L${x(0).toFixed(1)},${h - pad} Z`;
    const last = points[points.length - 1];

    return (
        <div className="flex flex-col gap-2">
            <svg viewBox={`0 0 ${w} ${h}`} className="h-40 w-full" role="img" aria-label={t('spending.cumulative_chart_aria')}>
                <defs>
                    <linearGradient id="fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--primary)" stopOpacity="0.22" />
                        <stop offset="100%" stopColor="var(--primary)" stopOpacity="0" />
                    </linearGradient>
                </defs>
                <path d={area} fill="url(#fill)" />
                <path d={line} fill="none" stroke="var(--primary)" strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
                <circle cx={x(points.length - 1)} cy={y(last.cumulative)} r="4.5" fill="var(--primary)" />
            </svg>
            <div className="text-muted-foreground flex justify-between px-1 font-mono text-xs tabular-nums">
                <span>{shortDate(points[0].date)}</span>
                <span className="text-primary font-semibold">
                    {money(last.cumulative, currency)} {t('spending.total')}
                </span>
                <span>{shortDate(last.date)}</span>
            </div>
        </div>
    );
}

function Fact({ label, value, sub, mono }: { label: string; value: string; sub?: string; mono?: boolean }) {
    return (
        <div className="bg-surface-card flex flex-col gap-1 px-4 py-4">
            <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</span>
            <span className={`text-primary text-lg font-semibold ${mono ? 'font-mono tabular-nums' : ''}`}>{value}</span>
            {sub && <span className="text-muted-foreground truncate text-xs">{sub}</span>}
        </div>
    );
}

export default function Stats({ event, categories, byCategory, bySource, overTime, facts }: Props) {
    const t = useT();
    // Resolve a category key to its colour/label via the family's category list.
    const catMap: CategoryMap = categoryMap(categories);
    const catColor = (k: string) => catColorOf(catMap, k);
    const catLabel = (t: TFn, k: string) => catLabelOf(t, catMap, k);
    const [grown, setGrown] = useState(false);
    useEffect(() => {
        const timer = setTimeout(() => setGrown(true), 60);
        return () => clearTimeout(timer);
    }, []);

    const fmt = (c: number) => money(c, event.currency);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.spending'), href: '/m/spending' },
        { title: event.name, href: route('spending.show', event.id) },
        { title: t('spending.stats'), href: route('spending.stats', event.id) },
    ];

    if (facts.count === 0) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={`${event.name} · ${t('spending.stats')}`} />
                <div className="mx-auto max-w-3xl p-4">
                    <h1 className="font-heading text-primary text-3xl">{event.name}</h1>
                    <p className="text-muted-foreground mt-6">{t('spending.stats_empty')}</p>
                </div>
            </AppLayout>
        );
    }

    const top = byCategory[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${event.name} · ${t('spending.stats')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
                {/* Editorial summary: the total woven into a sentence, not a hero tile. */}
                <header>
                    <h1 className="font-heading text-primary text-3xl">{event.name}</h1>
                    <p className="text-text-secondary mt-3 text-lg leading-relaxed">
                        <span className="font-heading text-primary text-2xl">{fmt(event.total)}</span>{' '}
                        {t('spending.stats_summary', {
                            count: facts.count,
                            spendNoun: t(facts.count === 1 ? 'spending.spend_one' : 'spending.spend_other'),
                            days: facts.days,
                            dayNoun: t(facts.days === 1 ? 'spending.day_one' : 'spending.day_other'),
                        })}
                        {facts.people > 1 && t('spending.stats_summary_each', { amount: fmt(facts.perPerson) })}.
                    </p>
                </header>

                {/* Where it went — the lead visualization. */}
                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-primary text-lg">{t('spending.where_it_went')}</h2>
                    <div className="flex flex-col gap-4">
                        {byCategory.map((c) => {
                            const color = catColor(c.key);
                            return (
                                <div key={c.key} className="flex flex-col gap-1.5">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="text-primary flex items-center gap-2 text-sm font-medium">
                                            <span className="size-2.5 rounded-full" style={{ background: color }} />
                                            {catLabel(t, c.key)}
                                        </span>
                                        <span className="text-text-secondary font-mono text-sm tabular-nums">
                                            {fmt(c.amount)} <span className="text-muted-foreground">· {c.pct}%</span>
                                        </span>
                                    </div>
                                    <Bar pct={c.pct} color={color} grown={grown} />
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* By payment method — only worth showing once a spend actually names a source. */}
                {bySource.some((s) => s.key !== 'none') && (
                    <section className="flex flex-col gap-4">
                        <h2 className="font-heading text-primary text-lg">{t('spending.by_payment_method')}</h2>
                        <div className="flex flex-col gap-4">
                            {bySource.map((s) => {
                                const color = SOURCE_COLOR[s.type] ?? SOURCE_COLOR.none;
                                return (
                                    <div key={s.key} className="flex flex-col gap-1.5">
                                        <div className="flex items-baseline justify-between gap-3">
                                            <span className="text-primary flex items-center gap-2 text-sm font-medium">
                                                <span className="size-2.5 rounded-full" style={{ background: color }} />
                                                {s.label}
                                                {s.type !== 'none' && (
                                                    <span className="text-muted-foreground text-xs font-normal">· {sourceTypeLabel(t, s.type)}</span>
                                                )}
                                            </span>
                                            <span className="text-text-secondary font-mono text-sm tabular-nums">
                                                {fmt(s.amount)} <span className="text-muted-foreground">· {s.pct}%</span>
                                            </span>
                                        </div>
                                        <Bar pct={s.pct} color={color} grown={grown} />
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}

                {/* Over the trip */}
                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-primary text-lg">{t('spending.over_the_trip')}</h2>
                    <TrendCurve points={overTime} currency={event.currency} t={t} />
                </section>

                {/* Quick facts — one panel, hairline-separated via a 1px gap over the border color. */}
                <section className="bg-border grid grid-cols-2 gap-px overflow-hidden rounded-2xl border sm:grid-cols-4">
                    <Fact label={t('spending.average_spend')} value={fmt(facts.average)} mono />
                    <Fact label={t('spending.daily_average')} value={fmt(facts.dailyAverage)} mono />
                    {facts.biggest && <Fact label={t('spending.biggest_spend')} value={fmt(facts.biggest.amount)} sub={facts.biggest.label} mono />}
                    {top && (
                        <Fact label={t('spending.top_category')} value={catLabel(t, top.key)} sub={t('spending.pct_of_spend', { pct: top.pct })} />
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
