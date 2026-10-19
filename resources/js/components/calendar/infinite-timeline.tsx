import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { forwardRef, Fragment, useEffect, useImperativeHandle, useLayoutEffect, useMemo, useRef, useState, type CSSProperties } from 'react';

import { LucideIcon } from '@/components/lucide-icon';
import {
    addDays,
    daysBetween,
    daysOf,
    filterItems,
    isOverdue,
    itemKey,
    parseDay,
    timelineRows,
    ymd,
    type CalFilter,
    type CalItem,
} from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

// The calendar 1.1 timeline (preview, see App\Support\Modules\Preview): one endless strip of days instead of a fixed
// window. It starts on the server's range and loads more (/m/calendar/items) as you near either edge; days added on
// the left shift the scroll position by their width, so the view doesn't jump. From feedback on the phone layout:
//  - one scale: 8 days across a phone, 14 on desktop. No zoom: the strip is endless, so a "month" or "year" view only
//    made the days smaller, and the feedback was to keep the days as they are;
//  - the header corner names the month of the first visible day (OCT), with the year highlighted when it isn't this
//    year, and a line marks where each month starts;
//  - a Today button floats on the right whenever today is off-screen;
//  - one row per item with its icon on the left (the column people like); a row shows while its item is on screen, and
//    while today is on screen, things that ended in the last OLD_DAYS keep theirs too. Far from today rows follow the
//    screen exactly (a "recent" margin there was just empty rows above whatever you scrolled to);
//  - phones: a faded title line on each row, solid while the item's bar is under it; completed items are hidden and
//    overdue ones are red.

export type TimelineHandle = { scrollPage: (dir: 1 | -1) => void; scrollToToday: () => void };

const SHORT = 3; // bars under this many days carry only an icon; the title goes beside them
const OLD_DAYS = 14;
const CHUNK = 28; // days fetched per edge load
const VISIBLE = { phone: 8, desktop: 14 }; // days across the visible track
const isWeekend = (d: string) => [0, 6].includes(parseDay(d).getDay());

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

export const InfiniteTimeline = forwardRef<
    TimelineHandle,
    {
        items: CalItem[];
        range: { from: string; to: string };
        filter: CalFilter;
        onMonth?: (firstVisibleDay: string) => void;
    }
>(function InfiniteTimeline({ items: pageItems, range: pageRange, filter, onMonth }, ref) {
    const t = useT();
    const locale = useLocale();
    const today = ymd(new Date());

    const [span, setSpanState] = useState(pageRange); // the loaded days
    // Mirrors `span` synchronously: a scroll event can fire before React re-renders with the new span, and the edge
    // loader must never ask for a range it already has (or is fetching).
    const spanRef = useRef(pageRange);
    const setSpan = (next: { from: string; to: string }) => {
        spanRef.current = next;
        setSpanState(next);
    };
    const [byKey, setByKey] = useState(() => new Map(pageItems.map((i) => [itemKey(i), i])));
    const merge = (list: CalItem[]) => setByKey((m) => new Map([...m, ...list.map((i) => [itemKey(i), i] as const)]));
    // The page's own items are the fresh truth for their range (they reload after every save): replace that slice.
    useEffect(() => {
        setByKey((m) => {
            const next = new Map([...m].filter(([, i]) => i.end < pageRange.from || i.start > pageRange.to));
            pageItems.forEach((i) => next.set(itemKey(i), i));
            return next;
        });
    }, [pageItems, pageRange.from, pageRange.to]);

    const days = useMemo(() => daysOf(span.from, span.to), [span.from, span.to]);
    const box = useRef<HTMLDivElement>(null);
    const nameCol = useRef<HTMLDivElement>(null);

    // Day width: VISIBLE days across the visible track (box minus the sticky name column).
    const [dayW, setDayW] = useState(0); // 0 until measured: nothing scrolls before the real width is known
    useLayoutEffect(() => {
        const measure = () => {
            const b = box.current;
            if (!b) return;
            const track = b.clientWidth - (nameCol.current?.offsetWidth ?? 48);
            setDayW(Math.max(20, track / (window.innerWidth < 640 ? VISIBLE.phone : VISIBLE.desktop)));
        };
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, []);

    // Scroll position → visible day window [first, last]; re-measured once per frame while scrolling.
    const [win, setWin] = useState<[number, number] | null>(null);
    const loading = useRef({ left: false, right: false });
    const shiftBy = useRef(0); // px to add to scrollLeft after days were prepended
    const scrollTo = useRef<string | null>(today >= pageRange.from && today <= pageRange.to ? today : pageRange.from);

    const loadLeft = async () => {
        if (loading.current.left) return;
        loading.current.left = true;
        const was = spanRef.current.from;
        const from = addDays(was, -CHUNK);
        const got = await fetchItems(from, addDays(was, -1));
        if (got && spanRef.current.from === was) {
            merge(got);
            shiftBy.current += daysBetween(from, was) * dayW;
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

    // After days are prepended, on a jump to a date, or when the day width changes (rotate, resize), put the scroll
    // where it belongs before paint. A width change keeps the same first visible day.
    const lastW = useRef(0);
    useLayoutEffect(() => {
        const b = box.current;
        if (!b || !dayW) return;
        if (lastW.current && lastW.current !== dayW) b.scrollLeft = (b.scrollLeft / lastW.current) * dayW;
        lastW.current = dayW;
        if (shiftBy.current) {
            b.scrollLeft += shiftBy.current;
            shiftBy.current = 0;
        }
        if (scrollTo.current) {
            const idx = days.indexOf(scrollTo.current);
            if (idx >= 0) b.scrollLeft = Math.max(0, (idx - 1) * dayW); // the day one column in, so yesterday shows
            scrollTo.current = null;
        }
    }, [days, dayW]);

    useEffect(() => {
        const b = box.current;
        if (!b) return;
        if (!dayW) return;
        let frame = 0;
        const measure = () => {
            frame = 0;
            const first = Math.max(0, Math.floor(b.scrollLeft / dayW));
            const last = Math.min(days.length - 1, Math.ceil((b.scrollLeft + b.clientWidth - (nameCol.current?.offsetWidth ?? 0)) / dayW) - 1);
            setWin((w) => (w && w[0] === first && w[1] === last ? w : [first, last]));
            if (first < 7) void loadLeft();
            if (last > days.length - 8) void loadRight();
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

    const firstVisible = win ? days[win[0]] : days[0];
    useEffect(() => {
        if (firstVisible) onMonth?.(firstVisible);
    }, [firstVisible?.slice(0, 7)]); // eslint-disable-line react-hooks/exhaustive-deps

    const todayIdx = days.indexOf(today);
    const todayInView = !!win && todayIdx >= win[0] && todayIdx <= win[1];

    const goToday = () => {
        const b = box.current;
        if (!b) return;
        if (todayIdx >= 0) return b.scrollTo({ left: Math.max(0, (todayIdx - 1) * dayW), behavior: 'smooth' });
        // Today isn't loaded (scrolled far away): start a fresh strip around it.
        const from = addDays(today, -CHUNK);
        const to = addDays(today, CHUNK);
        void fetchItems(from, to).then((got) => {
            if (got) merge(got);
            scrollTo.current = today;
            setSpan({ from, to });
        });
    };
    useImperativeHandle(ref, () => ({
        scrollPage: (dir) =>
            box.current?.scrollBy({ left: dir * (box.current.clientWidth - (nameCol.current?.offsetWidth ?? 0)), behavior: 'smooth' }),
        scrollToToday: goToday,
    }));
    const todayIsLeft = !!win && today < (days[win[0]] ?? '');

    // Rows: one per item, completed ones left out; on screen, or (with today on screen) ended in the last OLD_DAYS.
    const shown = filterItems([...byKey.values()], filter).filter((i) => !i.done);
    const allRows = timelineRows(shown, span.from, span.to);
    const near = (bars: (typeof allRows)[number]['bars'], from: number, to: number) =>
        bars.some((b) => b.offset <= to && b.offset + b.span - 1 >= from);
    const recentFrom = addDays(today, -OLD_DAYS);
    const rows = win
        ? allRows.filter((r) => near(r.bars, win[0], win[1]) || (todayInView && r.bars.some((b) => b.item.end >= recentFrom && b.item.end < today)))
        : allRows;
    const under = (bars: (typeof allRows)[number]['bars']) => !!win && near(bars, win[0], win[0] + 2);
    const late = (i: CalItem) => isOverdue(i, today);

    const monthLabel = firstVisible ? parseDay(firstVisible).toLocaleDateString(locale, { month: 'short' }).replace('.', '').toUpperCase() : '';
    const visibleYear = firstVisible?.slice(0, 4);
    const otherYear = visibleYear && visibleYear !== today.slice(0, 4);
    const px = (n: number) => `${n}px`;

    return (
        <div className="relative">
            <div ref={box} className="bg-surface-card max-h-[calc(100dvh-12rem)] max-w-full overflow-auto rounded-2xl border">
                <div className="flex w-max min-w-full">
                    {/* Name column: sticks to the left while the dates scroll under it. Its header cell names the month. */}
                    <div ref={nameCol} className="bg-surface-card sticky left-0 z-20 w-12 shrink-0 border-r sm:w-56 lg:w-48">
                        <div className="bg-surface-card sticky top-0 z-10 flex h-12 flex-col items-center justify-center border-b sm:items-start sm:px-3">
                            <span className="text-primary text-xs font-semibold tracking-wide">{monthLabel}</span>
                            {otherYear && (
                                <span className="bg-primary text-primary-foreground rounded px-1 text-[10px] leading-tight font-semibold tabular-nums">
                                    {visibleYear}
                                </span>
                            )}
                        </div>
                        {rows.map(({ key, item }) => (
                            <Link
                                key={key}
                                href={item.href}
                                title={item.title}
                                className="hover:bg-surface flex h-11 items-center justify-center gap-2 border-b px-3 text-sm sm:justify-start"
                            >
                                <LucideIcon name={item.icon} className={`size-4 shrink-0 ${late(item) ? 'text-destructive' : 'text-primary'}`} />
                                <span className={`hidden truncate sm:inline ${late(item) ? 'text-destructive' : ''}`}>{item.title}</span>
                            </Link>
                        ))}
                    </div>

                    {/* Track: day columns + bars, in px (the strip grows at both ends, so % can't work). */}
                    <div className="relative shrink-0" style={{ width: px(days.length * dayW) } as CSSProperties}>
                        <div className="pointer-events-none absolute inset-0 flex" aria-hidden>
                            {days.map((d) => (
                                <div
                                    key={d}
                                    style={{ width: px(dayW) }}
                                    className={`shrink-0 ${d.endsWith('-01') ? 'border-l' : ''} ${isWeekend(d) ? 'bg-foreground/[0.04]' : ''}`}
                                />
                            ))}
                        </div>
                        {todayIdx >= 0 && (
                            <div
                                className="bg-primary pointer-events-none absolute inset-y-0 w-px"
                                style={{ left: px((todayIdx + 0.5) * dayW) }}
                                aria-hidden
                            />
                        )}
                        <div className="bg-surface-card sticky top-0 z-10 flex h-12 border-b">
                            {days.map((d, i) => (
                                <div
                                    key={d}
                                    style={{ width: px(dayW) }}
                                    className={`text-muted-foreground relative flex shrink-0 flex-col items-center justify-center text-xs tabular-nums ${d.endsWith('-01') ? 'border-l' : ''} ${isWeekend(d) ? 'bg-foreground/[0.04]' : ''}`}
                                >
                                    <span className="text-[10px] uppercase">{parseDay(d).toLocaleDateString(locale, { weekday: 'narrow' })}</span>
                                    <span
                                        className={`flex size-6 items-center justify-center rounded-full ${i === todayIdx ? 'bg-primary text-primary-foreground font-semibold' : ''}`}
                                    >
                                        {Number(d.slice(8))}
                                    </span>
                                </div>
                            ))}
                        </div>
                        {rows.map(({ key, item: first, bars }) => (
                            // overflow-clip, not hidden: hidden makes the row a scroll container and the sticky phone
                            // title would stick inside the row instead of the box.
                            <div key={key} className="relative h-11 overflow-clip border-b">
                                {bars.map(({ item, offset, span: len }) => (
                                    <Fragment key={itemKey(item)}>
                                        <Link
                                            href={item.href}
                                            title={item.title}
                                            className={`absolute top-1.5 flex h-8 items-center gap-1.5 overflow-hidden rounded-lg text-sm font-medium whitespace-nowrap shadow-xs hover:brightness-95 max-sm:top-5 max-sm:h-5 max-sm:rounded-md ${len < SHORT ? 'justify-center px-0.5' : 'px-2'} ${late(item) ? 'bg-destructive/15 text-destructive' : itemTone(item.color)}`}
                                            style={{ left: px(offset * dayW + 2), width: px(Math.max(4, len * dayW - 4)) }}
                                        >
                                            <LucideIcon name={item.icon} className="size-4 shrink-0" />
                                            {len >= SHORT && <span className="truncate max-sm:hidden">{item.title}</span>}
                                        </Link>
                                        {len < SHORT && (
                                            <span
                                                className={`pointer-events-none absolute top-1.5 flex h-8 items-center pl-1.5 text-sm whitespace-nowrap max-sm:hidden ${late(item) ? 'text-destructive' : 'text-foreground'}`}
                                                style={{ left: px((offset + len) * dayW) }}
                                            >
                                                {item.title}
                                            </span>
                                        )}
                                    </Fragment>
                                ))}
                                <span
                                    className={`pointer-events-none sticky left-14 z-[5] block w-max max-w-[calc(100vw-8rem)] truncate pt-0.5 text-xs leading-4 font-medium transition-opacity sm:hidden ${late(first) ? 'text-destructive' : 'text-foreground'} ${under(bars) ? 'opacity-100' : 'opacity-40'}`}
                                >
                                    {first.title}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
                {rows.length === 0 && <p className="text-muted-foreground sticky left-0 p-4 text-sm">{t('calendar.nothing_range')}</p>}
            </div>

            {/* Back to today, on the side today is on. Only while today is off-screen. */}
            {win && !todayInView && (
                <button
                    type="button"
                    onClick={goToday}
                    className="bg-primary text-primary-foreground absolute top-14 right-3 z-30 flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium shadow-lg"
                >
                    {todayIsLeft && <ChevronLeft className="size-4" />}
                    {t('calendar.today')}
                    {!todayIsLeft && <ChevronRight className="size-4" />}
                </button>
            )}
        </div>
    );
});
