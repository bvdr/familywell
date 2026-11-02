import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { DayList, stripe, takesADay } from '@/components/calendar/agenda';
import { addDays, daysBetween, itemsOn, parseDay, timelineRows, type CalItem } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

const ROW_PX = 40;
const fmt = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(appLocale(), o);

// Timeline (design j6QDy4 04b, C6 / C7 / C8, D2): the big stuff, one row each. Only things that take a day (trips,
// fasts, holidays, birthdays: a 3pm dentist is a dot in Month and stays out of here), and only those touching the
// days on screen: no empty rows, nothing overlaps. Oldest on top. A name sits inside its bar when the bar is long
// enough, beside it when it's short. A NEXT row says what's coming. 1w gives each day a column with a dot per timed
// thing. Swipe (or the arrows) moves a week; rows that end leave the top, new ones join the bottom tinted 'new'.
export function TimelineView({
    items,
    ahead,
    range,
    today,
    selected,
    onSelect,
    onShift,
    onJump,
    canGoBack,
    onToggle,
}: {
    items: CalItem[];
    ahead: CalItem[];
    range: { from: string; to: string };
    today: string;
    selected: string;
    onSelect: (d: string) => void;
    onShift: (dir: 1 | -1) => void;
    onJump: (date: string) => void;
    canGoBack: boolean;
    onToggle: (i: CalItem) => void;
}) {
    const t = useT();
    const n = daysBetween(range.from, range.to) + 1;
    const days = Array.from({ length: n }, (_, k) => addDays(range.from, k));
    const week = n === 7;
    const col = (k: number) => `calc(${k} * 100% / ${n})`;

    // One row per thing, oldest first; a repeating one keeps every time it falls here on its row.
    const rows = timelineRows(items.filter(takesADay), range.from, range.to);
    // NEXT: the first thing coming that isn't already a row here (a repeating one may be both).
    const next = ahead.find((a) => !rows.some((r) => r.key === `${a.source}-${a.kind}-${a.id}`));

    // Rows that weren't on screen before this window joined at the bottom: tinted 'new' for a beat.
    const seen = useRef<Set<string>>(new Set(rows.map((r) => r.key)));
    const [fresh, setFresh] = useState<Set<string>>(new Set());
    const windowKey = range.from + range.to;
    useEffect(() => {
        const now = new Set(rows.map((r) => r.key));
        const added = [...now].filter((k) => !seen.current.has(k));
        seen.current = now;
        if (!added.length) return;
        setFresh(new Set(added));
        const id = window.setTimeout(() => setFresh(new Set()), 2500);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [windowKey]);

    const swipe = useRef<number | null>(null);
    const meta = (i: CalItem) => {
        const total = daysBetween(i.start, i.end) + 1;
        if (i.start < range.from) {
            const nth = daysBetween(i.start, range.from) + 1;
            return i.end <= range.to
                ? i.end === range.from
                    ? t('calendar.last_day')
                    : t('calendar.ends_on', { day: fmt(i.end, { weekday: 'short' }) })
                : t('calendar.day_of', { n: nth, total });
        }
        return total > 1 ? t('calendar.days', { count: total }) : fmt(i.start, { weekday: 'short' });
    };

    return (
        <div className="flex flex-col gap-5">
            <div
                className="bg-surface-card relative touch-pan-y overflow-hidden rounded-2xl border select-none"
                onPointerDown={(e) => (swipe.current = e.clientX)}
                onPointerUp={(e) => {
                    const dx = swipe.current === null ? 0 : e.clientX - swipe.current;
                    swipe.current = null;
                    if (Math.abs(dx) > 60 && (dx < 0 || canGoBack)) onShift(dx < 0 ? 1 : -1);
                }}
            >
                {/* Weekend columns shaded, and today's line through every row. */}
                <div className="pointer-events-none absolute inset-0" aria-hidden>
                    {days.map((d, k) =>
                        [0, 6].includes(parseDay(d).getDay()) ? (
                            <div key={d} className="bg-surface/70 absolute inset-y-0" style={{ left: col(k), width: col(1) }} />
                        ) : null,
                    )}
                    {today >= range.from && today <= range.to && (
                        <div
                            className="bg-primary absolute inset-y-0 w-0.5"
                            style={{ left: `calc(${daysBetween(range.from, today)} * 100% / ${n} + 100% / ${n * 2})` }}
                        />
                    )}
                </div>

                {/* Day header: letter + date (1w: the weekday and a dot per timed thing). Tap a day for its agenda. */}
                <div className="relative grid border-b" style={{ gridTemplateColumns: `repeat(${n}, minmax(0, 1fr))` }}>
                    {days.map((d) => {
                        const dots = week
                            ? itemsOn(items, d)
                                  .filter((i) => !takesADay(i))
                                  .slice(0, 3)
                            : [];
                        const isToday = d === today;
                        const on = d === selected && !isToday;
                        return (
                            <button
                                key={d}
                                type="button"
                                onClick={() => onSelect(d)}
                                aria-pressed={d === selected}
                                className="flex flex-col items-center gap-0.5 py-1.5"
                            >
                                <span className="text-muted-foreground font-mono text-[10px] font-semibold uppercase">
                                    {fmt(d, { weekday: week ? 'short' : 'narrow' })}
                                </span>
                                <span
                                    className={`flex items-center justify-center rounded-full tabular-nums ${week ? 'size-8 text-base' : 'size-6 text-sm'} ${
                                        isToday
                                            ? 'bg-accent-strong text-accent-strong-foreground font-bold'
                                            : on
                                              ? 'bg-primary text-primary-foreground font-bold'
                                              : 'text-primary'
                                    }`}
                                >
                                    {Number(d.slice(8))}
                                </span>
                                {week && (
                                    <span className="flex h-1.5 gap-0.5" aria-hidden>
                                        {dots.map((i) => (
                                            <span key={`${i.source}${i.id}${i.start}`} className={`size-1.5 rounded-full ${stripe(i.color)}`} />
                                        ))}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                {/* Off this week: a yellow pill back to today (1w). */}
                {week && (today < range.from || today > range.to) && (
                    <button
                        type="button"
                        onClick={() => onJump(today)}
                        className="bg-accent-strong text-accent-strong-foreground relative mx-3 mt-2 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
                    >
                        {today < range.from ? <ArrowLeft className="size-3" /> : null}
                        {t('calendar.today')}
                        {today > range.to ? <ArrowRight className="size-3" /> : null}
                    </button>
                )}

                {rows.length === 0 && <p className="text-muted-foreground relative px-4 py-6 text-center text-sm">{t('calendar.nothing_big')}</p>}
                {rows.map(({ key, item: i, bars }) => {
                    const { offset, span } = bars[0];
                    const before = i.start < range.from;
                    // Inside the bar when it's at least ~45% of the screen (a long name fits); otherwise beside it, on
                    // whichever side has room.
                    const inside = span / n >= 0.45;
                    const rightRoom = n - (offset + span);
                    const label = (
                        <span className="flex min-w-0 items-baseline gap-1.5">
                            {before && <ArrowLeft className="size-3.5 shrink-0 self-center" />}
                            <span className="truncate font-semibold">{i.title}</span>
                            <span className="text-muted-foreground shrink-0 font-mono text-[11px]">{meta(i)}</span>
                            {fresh.has(key) && (
                                <span className="bg-primary text-accent-strong shrink-0 rounded px-1 font-mono text-[10px] font-bold">
                                    {t('calendar.new_badge')}
                                </span>
                            )}
                        </span>
                    );
                    return (
                        <Link
                            key={key}
                            href={i.href}
                            className={`border-border/60 relative block border-b transition-colors duration-700 ${fresh.has(key) ? 'bg-accent-strong/25' : ''}`}
                            style={{ height: ROW_PX }}
                        >
                            {bars.map((b, k) => (
                                <span
                                    key={k}
                                    className={`absolute top-1.5 bottom-1.5 flex items-center overflow-hidden text-sm ${itemTone(i.color)} ${b.item.start < range.from ? '' : `rounded-l-lg border-l-4 ${stripe(i.color).replace('bg-', 'border-')}`} ${b.item.end > range.to ? '' : 'rounded-r-lg'}`}
                                    style={{ left: col(b.offset), width: col(b.span) }}
                                >
                                    {k === 0 && inside && <span className="min-w-0 px-2">{label}</span>}
                                </span>
                            ))}
                            {!inside && (
                                <span
                                    className="text-primary absolute top-0 bottom-0 flex items-center px-2 text-sm"
                                    style={
                                        rightRoom >= offset
                                            ? { left: col(offset + span), right: 0 }
                                            : { right: `calc(${n - offset} * 100% / ${n})`, left: 0, justifyContent: 'flex-end' }
                                    }
                                >
                                    {label}
                                </span>
                            )}
                        </Link>
                    );
                })}

                {/* NEXT: what's coming after these days; tapping it moves the timeline there. */}
                {next && (
                    <div className="relative flex justify-end p-2">
                        <button
                            type="button"
                            onClick={() => onJump(next.start)}
                            className="bg-surface text-primary flex max-w-full items-center gap-2 rounded-full border border-dashed px-3 py-1.5 text-sm"
                        >
                            <span className="text-muted-foreground font-mono text-[11px] font-semibold uppercase">{t('calendar.next_label')}</span>
                            <span className="truncate font-semibold">
                                {next.title} · {fmt(next.start, { weekday: 'short', month: 'short', day: 'numeric' })}
                            </span>
                            <ArrowRight className="size-4 shrink-0" />
                        </button>
                    </div>
                )}
            </div>

            {/* min-w-0 children: a long "who" line must truncate, not widen the page. */}
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
                <DayList items={items} day={selected} today={today} onToggle={onToggle} count />
                {/* Desktop (D2): further out, the next few months' big things. */}
                {ahead.length > 0 && (
                    <section className="hidden flex-col gap-2 md:flex">
                        <h2 className="flex items-baseline gap-2 px-1">
                            <span className="font-heading text-primary text-lg font-bold">{t('calendar.further_out')}</span>
                            <span className="text-muted-foreground text-sm">{t('calendar.next_few_months')}</span>
                        </h2>
                        <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                            {ahead.map((i) => {
                                const total = daysBetween(i.start, i.end) + 1;
                                const months = Math.round(daysBetween(today, i.start) / 30);
                                return (
                                    <Link key={`${i.source}${i.id}${i.start}`} href={i.href} className="flex items-center gap-3 px-4 py-3">
                                        <span className={`h-9 w-1 shrink-0 rounded-full ${stripe(i.color)}`} aria-hidden />
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate font-semibold">{i.title}</span>
                                            <span className="text-muted-foreground block truncate font-mono text-xs">
                                                {fmt(i.start, { month: 'short', day: 'numeric' })}
                                                {total > 1 &&
                                                    ` – ${fmt(i.end, { month: 'short', day: 'numeric' })} · ${t('calendar.days', { count: total })}`}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground shrink-0 text-sm">
                                            {months >= 1
                                                ? t('calendar.in_months', { count: months })
                                                : t('calendar.starts_in', { count: Math.max(1, daysBetween(today, i.start)) })}
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}
            </div>
        </div>
    );
}
