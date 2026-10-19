import { Link } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState, type CSSProperties } from 'react';

import { LucideIcon } from '@/components/lucide-icon';
import { addDays, daysOf, isOverdue, itemKey, parseDay, timelineRows, ymd, type CalItem } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

// Min width per day column: 44px on phones (a comfortable tap/read width, the box scrolls), 24px from lg up so a
// whole month fits a 1280px window like Notion's (the narrow weekday letter, a 2-digit date and the 24px today
// circle still fit). Set as Tailwind classes over a --days var, since inline styles can't be responsive.
const TRACK_MIN = 'min-w-[calc(var(--days)*44px)] lg:min-w-[calc(var(--days)*24px)]';
const SHORT = 3; // bars under this many days are too narrow for a title: it goes beside the bar instead
const isWeekend = (d: string) => [0, 6].includes(parseDay(d).getDay());
// Percent of the track width, so the columns stretch to fill the box when the window is narrower than it
// (a 14-day week zoom on desktop) and fall back to the TRACK_MIN floor + horizontal scroll when it is wider (a month on a phone).
const pct = (n: number, total: number) => `${(n / total) * 100}%`;

// Notion-style timeline: a sticky name column, a sticky date header, shaded weekends, a today line and one row
// per item with a bar from start to end. Only this box scrolls (both ways, capped to the viewport height so the
// header can stick), never the page. On phones the name column shrinks to the icon; the bar carries the title.
//
// `preview` (calendar 1.1, see App\Support\Modules\Preview), from feedback on the phone layout:
//  - An item that ended more than OLD_DAYS ago only keeps its row while its dates are scrolled into view, so the
//    rows are about now; it comes back when you swipe to it (rows appear/disappear as you scroll, accepted).
//  - Phones: each row carries its title as a small line at the top of the track, right of the icon column, faded
//    until the item's bar is under it, then solid. The bar sits slimmer below that line (so they never overlap) and
//    drops its own title there so the text never doubles.
//  - Completed items are left out, and overdue ones (isOverdue: open to-dos past their date) are red.
// When the preview ships, drop the `preview` checks and keep the new paths.
const OLD_DAYS = 14;

export function TimelineView({ items, range, preview = false }: { items: CalItem[]; range: { from: string; to: string }; preview?: boolean }) {
    const t = useT();
    const locale = useLocale();
    const days = daysOf(range.from, range.to);
    const allRows = timelineRows(preview ? items.filter((i) => !i.done) : items, range.from, range.to);
    const todayIdx = days.indexOf(ymd(new Date()));
    const box = useRef<HTMLDivElement>(null);
    const track = useRef<HTMLDivElement>(null);
    const todayCell = useRef<HTMLDivElement>(null);

    // The day columns currently in view [first, last], re-measured on scroll (once per frame). null until measured.
    const [win, setWin] = useState<[number, number] | null>(null);
    useEffect(() => {
        const b = box.current;
        if (!preview || !b) return;
        let frame = 0;
        const measure = () => {
            frame = 0;
            const tr = track.current;
            if (!tr) return;
            const dayW = tr.offsetWidth / days.length;
            // The track's visible part runs from scrollLeft to scrollLeft + (box width - the sticky name column).
            const first = Math.max(0, Math.floor(b.scrollLeft / dayW));
            const last = Math.min(days.length - 1, Math.ceil((b.scrollLeft + b.clientWidth - tr.offsetLeft) / dayW) - 1);
            setWin((w) => (w && w[0] === first && w[1] === last ? w : [first, last]));
        };
        const onScroll = () => {
            frame ||= requestAnimationFrame(measure);
        };
        measure();
        b.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        return () => {
            b.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            cancelAnimationFrame(frame);
        };
    }, [preview, days.length, range.from]);

    const today = ymd(new Date());
    const late = (i: CalItem) => preview && isOverdue(i, today);
    const cutoff = addDays(today, -OLD_DAYS);
    const inView = (bars: (typeof allRows)[number]['bars'], from: number, to: number) =>
        bars.some((b) => b.offset <= to && b.offset + b.span - 1 >= from);
    const rows = preview && win ? allRows.filter((r) => r.bars.some((b) => b.item.end >= cutoff) || inView(r.bars, win[0], win[1])) : allRows;
    // Phone row titles: solid while the item's bar is under the title (the first few visible days), faded otherwise.
    const under = (bars: (typeof allRows)[number]['bars']) => !!win && inView(bars, win[0], win[0] + 2);

    // Centre today in the box when it's in the window (a month zoom on a phone shows only ~7 days, so today
    // would otherwise usually start off-screen). Horizontal only: never moves the page.
    useEffect(() => {
        const b = box.current;
        const c = todayCell.current;
        if (!b) return;
        // Today outside this window: start at its first day, not wherever the previous window was scrolled to.
        if (!c) {
            b.scrollLeft = 0;
            return;
        }
        const br = b.getBoundingClientRect();
        const cr = c.getBoundingClientRect();
        b.scrollLeft += cr.left + cr.width / 2 - (br.left + br.width / 2);
    }, [range.from, todayIdx]);

    return (
        <div ref={box} className="bg-surface-card max-h-[calc(100dvh-12rem)] max-w-full overflow-auto rounded-2xl border">
            <div className="flex w-max min-w-full">
                {/* Name column: sticks to the left while the dates scroll under it. */}
                <div className="bg-surface-card sticky left-0 z-20 w-12 shrink-0 border-r sm:w-56 lg:w-48">
                    <div className="bg-surface-card sticky top-0 z-10 h-12 border-b" />
                    {rows.map(({ key, item }) => (
                        <Link
                            key={key}
                            href={item.href}
                            title={item.title}
                            className="hover:bg-surface flex h-11 items-center justify-center gap-2 border-b px-3 text-sm sm:justify-start"
                        >
                            <LucideIcon name={item.icon} className="text-primary size-4 shrink-0" />
                            <span
                                className={`hidden truncate sm:inline ${item.done ? 'text-muted-foreground line-through' : ''} ${late(item) ? 'text-destructive' : ''}`}
                            >
                                {item.title}
                            </span>
                        </Link>
                    ))}
                </div>

                {/* Track: day columns + bars, positioned in % of its width. */}
                <div ref={track} className={`relative flex-1 ${TRACK_MIN}`} style={{ '--days': days.length } as CSSProperties}>
                    {/* Weekend shading + today line: painted first, so the sticky header covers them and the bars sit on top. */}
                    <div className="pointer-events-none absolute inset-0 flex" aria-hidden>
                        {days.map((d) => (
                            <div key={d} className={`flex-1 ${isWeekend(d) ? 'bg-foreground/[0.04]' : ''}`} />
                        ))}
                    </div>
                    {todayIdx >= 0 && (
                        <div
                            className="bg-primary pointer-events-none absolute inset-y-0 w-px"
                            style={{ left: pct(todayIdx + 0.5, days.length) }}
                            aria-hidden
                        />
                    )}
                    <div className="bg-surface-card sticky top-0 z-10 flex h-12 border-b">
                        {days.map((d, i) => (
                            <div
                                key={d}
                                ref={i === todayIdx ? todayCell : undefined}
                                className={`text-muted-foreground flex flex-1 flex-col items-center justify-center text-xs tabular-nums ${isWeekend(d) ? 'bg-foreground/[0.04]' : ''}`}
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
                        // overflow-clip, not hidden, in the preview: hidden makes the row a scroll container and the
                        // sticky phone title would stick inside the row instead of the box.
                        <div key={key} className={`relative h-11 border-b ${preview ? 'overflow-clip' : 'overflow-hidden'}`}>
                            {bars.map(({ item, offset, span }) => (
                                <Fragment key={itemKey(item)}>
                                    <Link
                                        href={item.href}
                                        title={item.title}
                                        className={`absolute top-1.5 flex h-8 items-center gap-1.5 overflow-hidden rounded-lg ${preview ? 'max-sm:top-5 max-sm:h-5 max-sm:rounded-md' : ''} text-sm font-medium whitespace-nowrap shadow-xs hover:brightness-95 ${span < SHORT ? 'justify-center px-0.5' : 'px-2'} ${late(item) ? 'bg-destructive/15 text-destructive' : itemTone(item.color)} ${item.done ? 'line-through opacity-60' : ''}`}
                                        style={{ left: `calc(${pct(offset, days.length)} + 2px)`, width: `calc(${pct(span, days.length)} - 4px)` }}
                                    >
                                        <LucideIcon name={item.icon} className="size-4 shrink-0" />
                                        {span >= SHORT && <span className={`truncate ${preview ? 'max-sm:hidden' : ''}`}>{item.title}</span>}
                                    </Link>
                                    {/* Gantt-style outside label for short bars (which carry only a centred icon, as one desktop day is
                                        24px); the row clips the label at the window's edge. */}
                                    {span < SHORT && (
                                        <span
                                            className={`pointer-events-none absolute top-1.5 flex h-8 items-center pl-1.5 text-sm whitespace-nowrap ${late(item) ? 'text-destructive' : 'text-foreground'} ${preview ? 'max-sm:hidden' : ''} ${item.done ? 'text-muted-foreground line-through' : ''}`}
                                            style={{ left: pct(offset + span, days.length) }}
                                        >
                                            {item.title}
                                        </span>
                                    )}
                                </Fragment>
                            ))}
                            {preview && (
                                <span
                                    className={`pointer-events-none sticky left-14 ${late(first) ? 'text-destructive' : 'text-foreground'} z-[5] block w-max max-w-[calc(100vw-8rem)] truncate pt-0.5 text-xs leading-4 font-medium transition-opacity sm:hidden ${under(bars) ? 'opacity-100' : 'opacity-40'} ${first.done ? 'line-through' : ''}`}
                                >
                                    {first.title}
                                </span>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            {rows.length === 0 && <p className="text-muted-foreground sticky left-0 p-4 text-sm">{t('calendar.nothing_range')}</p>}
        </div>
    );
}
