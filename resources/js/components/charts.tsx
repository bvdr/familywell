import { animate, motion, useReducedMotion } from 'motion/react';
import { useEffect, useId, useState, type ReactNode } from 'react';

// Shared analytics chart primitives, extracted from the Spending analytics screen so the Vehicles "Costs"
// screen renders the exact same smooth curve / breakdown bar / count-up without a chart library or a second
// copy. Everything is theme-token driven so it tracks the active skin.

export type TrendPoint = { date: string; value: number };

/**
 * A smooth (rounded) line through a series of points, built as a Catmull-Rom spline converted to cubic
 * beziers — a curve that passes through every point but bends smoothly between them (straight segments would
 * read as jagged). The area under it fades in and the line draws itself on; both respect reduced motion.
 *
 * Generic over what a point MEANS: `formatDate` labels the ends, `renderPeak` renders the centre callout
 * (e.g. the peak spend), so both the money curve and the consumption curve reuse it unchanged.
 */
export function TrendCurve({
    points,
    formatDate,
    renderPeak,
    emptyLabel,
    ariaLabel,
    color = 'var(--primary)',
}: {
    points: TrendPoint[];
    formatDate: (d: string) => string;
    renderPeak?: (peak: TrendPoint) => ReactNode;
    emptyLabel: string;
    ariaLabel: string;
    color?: string;
}) {
    // Hook must run before any early return so it stays unconditional. Under reduced motion we snap.
    const reduced = useReducedMotion();
    // Unique gradient id so two curves on one page (spend + consumption) don't share a <defs> fill.
    const gradientId = useId();

    if (points.length < 2 || points.every((p) => p.value === 0)) {
        return <p className="text-muted-foreground py-10 text-center text-sm">{emptyLabel}</p>;
    }

    const w = 600;
    const h = 190;
    const pad = 12;
    const max = Math.max(...points.map((p) => p.value)) || 1;
    const x = (i: number) => pad + (i * (w - pad * 2)) / (points.length - 1);
    const y = (v: number) => h - pad - (v / max) * (h - pad * 2);

    // Catmull-Rom -> bezier: each segment's control points are derived from the neighbouring samples.
    const pts = points.map((p, i) => ({ x: x(i), y: y(p.value) }));
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
    const peak = points.reduce((m, p) => (p.value > m.value ? p : m), points[0]);

    return (
        <div className="flex flex-col gap-2">
            <svg viewBox={`0 0 ${w} ${h}`} className="h-48 w-full" role="img" aria-label={ariaLabel}>
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity="0.22" />
                        <stop offset="100%" stopColor={color} stopOpacity="0" />
                    </linearGradient>
                </defs>
                {/* Area fill fades and scales up from its base. transform-box: fill-box so scaleY's origin is
                    the element's own bottom edge. Keyed by `area` so a data change remounts + replays. */}
                <motion.path
                    key={`area-${area}`}
                    d={area}
                    fill={`url(#${gradientId})`}
                    style={{ transformBox: 'fill-box', transformOrigin: 'bottom' }}
                    initial={reduced ? false : { opacity: 0, scaleY: 0.85 }}
                    animate={{ opacity: 1, scaleY: 1 }}
                    transition={{ duration: 0.6, ease: 'easeOut', delay: 0.12 }}
                />
                {/* Line draws itself on via pathLength 0 -> 1. Keyed by `d` so it re-draws on data change. */}
                <motion.path
                    key={`line-${d}`}
                    d={d}
                    fill="none"
                    stroke={color}
                    strokeWidth="2.5"
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    initial={reduced ? false : { pathLength: 0 }}
                    animate={{ pathLength: 1 }}
                    transition={{ duration: 0.8, ease: 'easeOut' }}
                />
            </svg>
            <div className="text-muted-foreground flex justify-between px-1 font-mono text-xs tabular-nums">
                <span>{formatDate(points[0].date)}</span>
                {renderPeak ? renderPeak(peak) : <span />}
                <span>{formatDate(points[points.length - 1].date)}</span>
            </div>
        </div>
    );
}

/** A single breakdown bar that grows its width in, with a small per-row stagger (reduced motion snaps). */
export function Bar({ pct, color, index }: { pct: number; color: string; index: number }) {
    const reduced = useReducedMotion();
    return (
        <div className="bg-surface h-2.5 overflow-hidden rounded-full">
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

/**
 * Count an integer up from 0 on mount and whenever the target changes (e.g. a money total in minor units).
 * Each animated frame is rounded so the caller can format it as currency. Reduced motion skips to the value.
 */
export function useCountUp(target: number, reduced: boolean) {
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
