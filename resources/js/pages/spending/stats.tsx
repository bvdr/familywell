import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Cat = { key: string; amount: number; pct: number };
type Point = { date: string; amount: number; cumulative: number };
type Props = {
    event: { id: number; name: string; currency: string; total: number };
    byCategory: Cat[];
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

// An earthy categorical palette, harmonized with the forest/cream brand rather than a stock rainbow.
const CATEGORY_META: Record<string, { label: string; color: string }> = {
    food: { label: 'Food', color: '#B5623C' }, // terracotta
    transport: { label: 'Transport', color: '#2F6B44' }, // forest
    lodging: { label: 'Lodging', color: '#C99A3A' }, // gold
    activities: { label: 'Activities', color: '#4F7A6B' }, // muted teal-green
    other: { label: 'Other', color: '#9A8C7A' }, // taupe
};
const catMeta = (k: string) => CATEGORY_META[k] ?? { label: k.charAt(0).toUpperCase() + k.slice(1), color: '#9A8C7A' };

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
function TrendCurve({ points, currency }: { points: Point[]; currency: string }) {
    if (points.length < 2) {
        return <p className="text-muted-foreground py-6 text-center text-sm">A trend appears once there are spends on two or more days.</p>;
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
            <svg viewBox={`0 0 ${w} ${h}`} className="h-40 w-full" role="img" aria-label="Cumulative spending over time">
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
                <span className="text-primary font-semibold">{money(last.cumulative, currency)} total</span>
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

export default function Stats({ event, byCategory, overTime, facts }: Props) {
    const [grown, setGrown] = useState(false);
    useEffect(() => {
        const t = setTimeout(() => setGrown(true), 60);
        return () => clearTimeout(t);
    }, []);

    const fmt = (c: number) => money(c, event.currency);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Spending', href: '/m/spending' },
        { title: event.name, href: route('spending.show', event.id) },
        { title: 'Stats', href: route('spending.stats', event.id) },
    ];

    if (facts.count === 0) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={`${event.name} · Stats`} />
                <div className="mx-auto max-w-3xl p-4">
                    <h1 className="font-heading text-primary text-3xl">{event.name}</h1>
                    <p className="text-muted-foreground mt-6">No spends yet, add a few and the numbers will tell their story here.</p>
                </div>
            </AppLayout>
        );
    }

    const top = byCategory[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${event.name} · Stats`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
                {/* Editorial summary: the total woven into a sentence, not a hero tile. */}
                <header>
                    <h1 className="font-heading text-primary text-3xl">{event.name}</h1>
                    <p className="text-text-secondary mt-3 text-lg leading-relaxed">
                        <span className="font-heading text-primary text-2xl">{fmt(event.total)}</span> across {facts.count}{' '}
                        {facts.count === 1 ? 'spend' : 'spends'} over {facts.days} {facts.days === 1 ? 'day' : 'days'}
                        {facts.people > 1 && (
                            <>
                                , about <span className="text-primary font-medium">{fmt(facts.perPerson)}</span> each
                            </>
                        )}
                        .
                    </p>
                </header>

                {/* Where it went — the lead visualization. */}
                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-primary text-lg">Where it went</h2>
                    <div className="flex flex-col gap-4">
                        {byCategory.map((c) => {
                            const meta = catMeta(c.key);
                            return (
                                <div key={c.key} className="flex flex-col gap-1.5">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <span className="text-primary flex items-center gap-2 text-sm font-medium">
                                            <span className="size-2.5 rounded-full" style={{ background: meta.color }} />
                                            {meta.label}
                                        </span>
                                        <span className="text-text-secondary font-mono text-sm tabular-nums">
                                            {fmt(c.amount)} <span className="text-muted-foreground">· {c.pct}%</span>
                                        </span>
                                    </div>
                                    <Bar pct={c.pct} color={meta.color} grown={grown} />
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* Over the trip */}
                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-primary text-lg">Over the trip</h2>
                    <TrendCurve points={overTime} currency={event.currency} />
                </section>

                {/* Quick facts — one panel, hairline-separated via a 1px gap over the border color. */}
                <section className="bg-border grid grid-cols-2 gap-px overflow-hidden rounded-2xl border sm:grid-cols-4">
                    <Fact label="Average spend" value={fmt(facts.average)} mono />
                    <Fact label="Daily average" value={fmt(facts.dailyAverage)} mono />
                    {facts.biggest && <Fact label="Biggest spend" value={fmt(facts.biggest.amount)} sub={facts.biggest.label} mono />}
                    {top && <Fact label="Top category" value={catMeta(top.key).label} sub={`${top.pct}% of spend`} />}
                </section>
            </div>
        </AppLayout>
    );
}
