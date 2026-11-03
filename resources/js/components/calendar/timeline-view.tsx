import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { forwardRef, useEffect, useImperativeHandle, useLayoutEffect, useMemo, useRef, useState } from 'react';

import { DayList, stripe, takesADay } from '@/components/calendar/agenda';
import { useIsMobile } from '@/hooks/use-mobile';
import { addDays, daysBetween, daysOf, filterItems, itemKey, itemsOn, parseDay, timelineRows, type CalFilter, type CalItem } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

const ROW_PX = 40;
const LINGER = 14; // days a thing that ended keeps its row after it scrolls off the left edge
const AHEAD = 42; // days before it starts that a coming thing gets its row (6 weeks past the screen's right edge)
const CHUNK = 42; // days fetched per edge load: more than the widest zoom (35), so one load always fills a screen
const ACROSS = { week: 7, twoweeks: 14, month: 35 } as const; // days across the screen per zoom
const fmt = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(appLocale(), o);
const px = (n: number) => `${n}px`;

export type TimelineHandle = { scrollPage: (dir: 1 | -1) => void; scrollToDay: (d: string) => void };

async function fetchItems(from: string, to: string): Promise<CalItem[] | null> {
    try {
        const res = await fetch(`/m/calendar/items?from=${from}&to=${to}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        return res.ok ? ((await res.json()) as { items: CalItem[] }).items : null;
    } catch {
        return null; // offline: the strip just stops growing until the next scroll
    }
}

// Timeline (design j6QDy4 04b, C6 / C7 / C8, D2): the big stuff, one row each. Only things that take a day (trips,
// fasts, holidays: a 3pm dentist is a dot in Month and stays out of here), and only those touching the days on screen:
// no empty rows, nothing overlaps. Oldest on top. A name sits inside its bar when the bar is long enough, beside it
// when it's short. A NEXT row says what's coming.
// It scrolls freely, like the 1.1 endless timeline it replaced (feedback 2026-11-03: the design's one-week-per-swipe
// felt stuck): a native sideways scroll that loads CHUNK more days as it nears either edge. Zoom sets how many days fit
// across (1w / 2w / 5w). Rows that join while scrolling are tinted 'new' for a beat.
export const TimelineView = forwardRef<
    TimelineHandle,
    {
        items: CalItem[];
        ahead: CalItem[];
        filter: CalFilter;
        range: { from: string; to: string };
        zoom: keyof typeof ACROSS;
        today: string;
        selected: string;
        onSelect: (d: string) => void;
        onVisible: (from: string, to: string) => void;
        onToggle: (i: CalItem) => void;
    }
>(function TimelineView({ items: pageItems, ahead, filter, range, zoom, today, selected, onSelect, onVisible, onToggle }, ref) {
    const t = useT();
    const phone = useIsMobile();
    // A phone has 1w and 2w; a 5w zoom remembered from desktop shows as 2w there.
    const across = phone && zoom === 'month' ? ACROSS.twoweeks : ACROSS[zoom];
    const week = across === 7;

    // The loaded days. Mirrored in a ref: a scroll event can fire before React re-renders with the new span, and an
    // edge load must never ask for days it already has.
    const [span, setSpanState] = useState(range);
    const spanRef = useRef(range);
    const setSpan = (next: { from: string; to: string }) => {
        spanRef.current = next;
        setSpanState(next);
    };
    const [byKey, setByKey] = useState(() => new Map(pageItems.map((i) => [itemKey(i), i])));
    const merge = (list: CalItem[]) => setByKey((m) => new Map([...m, ...list.map((i) => [itemKey(i), i] as const)]));
    // The page's own items are the fresh truth for their range (they reload after every save and on a zoom change):
    // replace that slice, and grow the strip to cover it.
    useEffect(() => {
        setByKey((m) => {
            const next = new Map([...m].filter(([, i]) => i.end < range.from || i.start > range.to));
            pageItems.forEach((i) => next.set(itemKey(i), i));
            return next;
        });
        const s = spanRef.current;
        if (range.from < s.from || range.to > s.to)
            setSpan({ from: range.from < s.from ? range.from : s.from, to: range.to > s.to ? range.to : s.to });
    }, [pageItems, range.from, range.to]);

    const days = useMemo(() => daysOf(span.from, span.to), [span.from, span.to]);
    const all = useMemo(() => filterItems([...byKey.values()], filter), [byKey, filter]);
    const box = useRef<HTMLDivElement>(null);

    // Day width: `across` days fill the box. 0 until measured, so nothing scrolls before the real width is known.
    const [boxW, setBoxW] = useState(0);
    useLayoutEffect(() => {
        const b = box.current;
        if (!b) return;
        const ro = new ResizeObserver(() => setBoxW(b.clientWidth));
        ro.observe(b);
        setBoxW(b.clientWidth);
        return () => ro.disconnect();
    }, []);
    const dayW = boxW / across;

    // Scroll position → the visible days [first, last], re-measured once per frame while scrolling.
    // Kept as dates, not indexes into `days`: days get prepended (every index shifts) or replaced (a jump to a far
    // date) before the next scroll measure, and a stale index read past the end crashed the page (iPhone, 2026-11-03).
    const [shown, setShown] = useState<[string, string] | null>(null);
    const loading = useRef({ left: false, right: false });
    const shiftBy = useRef(0); // px to add to scrollLeft after days were prepended
    const scrollTo = useRef<string | null>(range.from);

    const loadLeft = async () => {
        if (loading.current.left) return;
        loading.current.left = true;
        const was = spanRef.current.from;
        const from = addDays(was, -CHUNK);
        const got = await fetchItems(from, addDays(was, -1));
        if (got && spanRef.current.from === was) {
            merge(got);
            shiftBy.current += CHUNK;
            setSpan({ ...spanRef.current, from });
        }
        loading.current.left = false;
    };
    const loadRight = async () => {
        if (loading.current.right) return;
        loading.current.right = true;
        const was = spanRef.current.to;
        const to = addDays(was, CHUNK);
        const got = await fetchItems(addDays(was, 1), to);
        if (got && spanRef.current.to === was) {
            merge(got);
            setSpan({ ...spanRef.current, to });
        }
        loading.current.right = false;
    };

    // Before paint: after days were prepended (keep what's on screen), on a jump to a day, or when the day width
    // changes (zoom, rotate: keep the same first visible day).
    const lastW = useRef(0);
    useLayoutEffect(() => {
        const b = box.current;
        if (!b || !dayW) return;
        if (lastW.current && lastW.current !== dayW) b.scrollLeft = (b.scrollLeft / lastW.current) * dayW;
        lastW.current = dayW;
        if (shiftBy.current) {
            b.scrollLeft += shiftBy.current * dayW;
            shiftBy.current = 0;
        }
        if (scrollTo.current) {
            const idx = days.indexOf(scrollTo.current);
            if (idx >= 0) b.scrollLeft = idx * dayW;
            scrollTo.current = null;
        }
    }, [days, dayW]);

    useEffect(() => {
        const b = box.current;
        if (!b || !dayW) return;
        let frame = 0;
        const measure = () => {
            frame = 0;
            const first = Math.min(days.length - 1, Math.max(0, Math.floor(b.scrollLeft / dayW + 0.01)));
            const last = Math.min(days.length - 1, Math.ceil((b.scrollLeft + b.clientWidth) / dayW - 0.01) - 1);
            const from = days[first];
            const to = days[Math.max(first, last)];
            setShown((w) => (w && w[0] === from && w[1] === to ? w : [from, to]));
            if (first < across) void loadLeft();
            if (last > days.length - 1 - across - AHEAD) void loadRight(); // AHEAD: the coming rows need those days
        };
        const onScroll = () => {
            frame ||= requestAnimationFrame(measure);
        };
        measure();
        b.addEventListener('scroll', onScroll, { passive: true });
        return () => {
            b.removeEventListener('scroll', onScroll);
            cancelAnimationFrame(frame);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- re-bind when the strip or day width changes
    }, [days, dayW]);

    const firstDay = shown?.[0] ?? range.from;
    const lastDay = shown?.[1] ?? range.to;
    // The window as columns of the loaded strip (for the rows and labels).
    const win = shown && ([daysBetween(span.from, firstDay), daysBetween(span.from, lastDay)] as const);
    useEffect(() => onVisible(firstDay, lastDay), [firstDay, lastDay]); // eslint-disable-line react-hooks/exhaustive-deps

    // Go to a day: scroll there if it's loaded, else start a fresh strip around it.
    const scrollToDay = (d: string) => {
        const b = box.current;
        const idx = days.indexOf(d);
        if (b && idx >= 0) return b.scrollTo({ left: idx * dayW, behavior: 'smooth' });
        const from = addDays(d, -CHUNK);
        const to = addDays(d, CHUNK);
        void fetchItems(from, to).then((got) => {
            if (!got) return;
            merge(got);
            scrollTo.current = d;
            setSpan({ from, to });
        });
    };
    // Today one column in, so yesterday shows; the rest open on their own day.
    const goToday = () => scrollToDay(addDays(today, -1));
    useImperativeHandle(ref, () => ({
        scrollPage: (dir) => box.current?.scrollBy({ left: dir * box.current.clientWidth, behavior: 'smooth' }),
        scrollToDay,
    }));

    // One row per thing touching the screen, oldest first; a repeating one keeps every time it falls here on its row.
    const rows = timelineRows(all.filter(takesADay), span.from, span.to).filter(
        // A thing that ended stays until you've scrolled LINGER days past its end, and a coming one shows up AHEAD days
        // before it reaches the screen (feedback 2026-11-03: rows popping in and out exactly at the edges felt jumpy).
        ({ bars }) => !win || bars.some((b) => b.offset <= win[1] + AHEAD && b.offset + b.span - 1 >= win[0] - LINGER),
    );
    // NEXT: the first thing coming after the screen that isn't already a row (loaded, or the server's months ahead).
    const next = [...all.filter(takesADay), ...ahead]
        .filter((a) => a.start > lastDay && !rows.some((r) => r.key === `${a.source}-${a.kind}-${a.id}`))
        .sort((a, b) => a.start.localeCompare(b.start))[0];

    // Rows that join because you scrolled: tinted 'new' for a beat. Rows that appear because more days loaded while the
    // screen stood still (the first edge loads, a save) are not news.
    const seen = useRef<{ keys: Set<string>; first: string } | null>(null);
    const [fresh, setFresh] = useState<Set<string>>(new Set());
    const rowKeys = rows.map((r) => r.key).join('|');
    useEffect(() => {
        if (!win) return;
        const now = new Set(rows.map((r) => r.key));
        const moved = !!seen.current && seen.current.first !== firstDay;
        const added = moved ? [...now].filter((k) => !seen.current!.keys.has(k)) : [];
        seen.current = { keys: now, first: firstDay };
        if (!added.length) return;
        setFresh(new Set(added));
        const id = window.setTimeout(() => setFresh(new Set()), 2500);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKeys, firstDay]);

    const meta = (i: CalItem) => {
        const total = daysBetween(i.start, i.end) + 1;
        if (i.start > lastDay) return t('calendar.starts_on', { day: fmt(i.start, { month: 'short', day: 'numeric' }) });
        if (i.end < firstDay) return t('calendar.ended_on', { day: fmt(i.end, { month: 'short', day: 'numeric' }) });
        if (i.start < firstDay) {
            return i.end <= lastDay
                ? i.end === firstDay
                    ? t('calendar.last_day')
                    : t('calendar.ends_on', { day: fmt(i.end, { weekday: 'short' }) })
                : t('calendar.day_of', { n: daysBetween(i.start, firstDay) + 1, total });
        }
        return total > 1 ? t('calendar.days', { count: total }) : fmt(i.start, { weekday: 'short' });
    };
    // A to-do ticked under the strip: flip the loaded copy too, so a day loaded by scrolling doesn't snap back.
    const toggle = (i: CalItem) => {
        merge([{ ...i, done: !i.done }]);
        onToggle(i);
    };

    const trackW = days.length * dayW;
    const todayIdx = days.indexOf(today);
    const todayInView = !!win && todayIdx >= win[0] && todayIdx <= win[1];
    const viewL = win ? win[0] * dayW : 0;
    const viewR = win ? (win[1] + 1) * dayW : boxW;

    return (
        <div className="flex flex-col gap-5">
            <div className="bg-surface-card relative overflow-hidden rounded-2xl border">
                <div ref={box} data-scroll-x className="overflow-x-auto overflow-y-hidden overscroll-x-contain select-none [scrollbar-width:none]">
                    <div className="relative" style={{ width: px(trackW) }}>
                        {/* Weekend columns shaded, and today's line through every row. */}
                        <div className="pointer-events-none absolute inset-0" aria-hidden>
                            {days.map((d, k) =>
                                [0, 6].includes(parseDay(d).getDay()) ? (
                                    <div key={d} className="bg-surface/70 absolute inset-y-0" style={{ left: px(k * dayW), width: px(dayW) }} />
                                ) : null,
                            )}
                            {todayIdx >= 0 && <div className="bg-primary absolute inset-y-0 w-0.5" style={{ left: px((todayIdx + 0.5) * dayW) }} />}
                        </div>

                        {/* Day header: letter + date (1w: the weekday and a dot per timed thing). Tap a day for its agenda. */}
                        <div className="relative flex border-b">
                            {days.map((d) => {
                                const dots = week
                                    ? itemsOn(all, d)
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
                                        className={`flex shrink-0 flex-col items-center gap-0.5 py-1.5 ${d.endsWith('-01') ? 'border-l' : ''}`}
                                        style={{ width: px(dayW) }}
                                    >
                                        <span className="text-muted-foreground font-mono text-[10px] font-semibold uppercase">
                                            {d.endsWith('-01') && !week ? fmt(d, { month: 'short' }) : fmt(d, { weekday: week ? 'short' : 'narrow' })}
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
                                                    <span key={itemKey(i)} className={`size-1.5 rounded-full ${stripe(i.color)}`} />
                                                ))}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        {rows.map(({ key, item: i, bars }) => {
                            const { offset, span: len } = bars[0];
                            const before = i.start < firstDay;
                            // Inside the bar when ~45% of the screen shows it (a long name fits); otherwise beside it, on
                            // whichever side of the screen has room (an ended one: at the left edge, after its bar).
                            const barL = offset * dayW;
                            const barR = (offset + len) * dayW;
                            const inside = Math.min(barR, viewR) - Math.max(barL, viewL) >= 0.45 * boxW;
                            const right = viewR - barR >= barL - viewL;
                            const after = i.start > lastDay;
                            // Beside a bar off either edge, the label holds on at that edge.
                            const edgeL = Math.max(barR, viewL);
                            const edgeR = Math.min(barL, viewR);
                            const label = (
                                <span className="flex min-w-0 items-baseline gap-1.5">
                                    {before && <ArrowLeft className="size-3.5 shrink-0 self-center" />}
                                    <span className="truncate font-semibold">{i.title}</span>
                                    <span className="text-muted-foreground shrink-0 font-mono text-[11px]">{meta(i)}</span>
                                    {after && <ArrowRight className="size-3.5 shrink-0 self-center" />}
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
                                        // overflow-clip, not hidden: hidden makes the bar a scroll container and the sticky
                                        // label would stick inside the bar instead of the screen's left edge.
                                        <span
                                            key={k}
                                            className={`absolute top-1.5 bottom-1.5 flex items-center overflow-clip text-sm ${itemTone(i.color)} ${b.item.start < span.from ? '' : `rounded-l-lg border-l-4 ${stripe(i.color).replace('bg-', 'border-')}`} ${b.item.end > span.to ? '' : 'rounded-r-lg'}`}
                                            style={{ left: px(b.offset * dayW), width: px(b.span * dayW) }}
                                        >
                                            {k === 0 && inside && (
                                                // Sticks to the screen's left edge while the bar's start scrolls away.
                                                <span className="sticky left-0 min-w-0 px-2" style={{ maxWidth: px(Math.min(b.span * dayW, boxW)) }}>
                                                    {label}
                                                </span>
                                            )}
                                        </span>
                                    ))}
                                    {!inside && (
                                        <span
                                            className="text-primary absolute top-0 bottom-0 flex items-center px-2 text-sm whitespace-nowrap"
                                            style={
                                                right
                                                    ? { left: px(edgeL), maxWidth: px(Math.max(120, viewR - edgeL)) }
                                                    : {
                                                          right: px(trackW - edgeR),
                                                          maxWidth: px(Math.max(120, edgeR - viewL)),
                                                          justifyContent: 'flex-end',
                                                      }
                                            }
                                        >
                                            {label}
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                </div>

                {rows.length === 0 && <p className="text-muted-foreground px-4 py-6 text-center text-sm">{t('calendar.nothing_big')}</p>}

                {/* Under the rows: back to Today (while it's off screen, on the side it's on) and NEXT, what's coming
                    after these days; tapping either scrolls there. */}
                {((win && !todayInView) || next) && (
                    <div className="flex items-center gap-2 p-2">
                        {win && !todayInView && (
                            <button
                                type="button"
                                onClick={goToday}
                                className={`bg-accent-strong text-accent-strong-foreground inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1.5 text-sm font-bold ${today < firstDay ? '' : 'order-last ml-auto'}`}
                            >
                                {today < firstDay && <ArrowLeft className="size-4" />}
                                {t('calendar.today')}
                                {today > lastDay && <ArrowRight className="size-4" />}
                            </button>
                        )}
                        {next && (
                            <button
                                type="button"
                                onClick={() => scrollToDay(next.start)}
                                className="bg-surface text-primary ml-auto flex min-w-0 items-center gap-2 rounded-full border border-dashed px-3 py-1.5 text-sm"
                            >
                                <span className="text-muted-foreground font-mono text-[11px] font-semibold uppercase">
                                    {t('calendar.next_label')}
                                </span>
                                <span className="truncate font-semibold">
                                    {next.title} · {fmt(next.start, { weekday: 'short', month: 'short', day: 'numeric' })}
                                </span>
                                <ArrowRight className="size-4 shrink-0" />
                            </button>
                        )}
                    </div>
                )}
            </div>

            {/* min-w-0 children: a long "who" line must truncate, not widen the page. */}
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2 [&>*]:min-w-0">
                <DayList items={all} day={selected} today={today} onToggle={toggle} count />
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
                                    <Link key={itemKey(i)} href={i.href} className="flex items-center gap-3 px-4 py-3">
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
});
