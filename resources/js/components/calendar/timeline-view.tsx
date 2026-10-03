import { Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import { LucideIcon } from '@/components/lucide-icon';
import { daysOf, itemKey, parseDay, timelineRows, ymd, type CalItem } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

const COL = 44; // min px per day column: fits a 2-digit date + weekday letter, keeps a month ~1350px wide
const SHORT = 3; // bars under this many days are too narrow for a title: it goes beside the bar instead
const isWeekend = (d: string) => [0, 6].includes(parseDay(d).getDay());
// Percent of the track width, so the columns stretch to fill the box when the window is narrower than it
// (a 14-day week zoom on desktop) and fall back to COL px + horizontal scroll when it is wider (a month on a phone).
const pct = (n: number, total: number) => `${(n / total) * 100}%`;

// Notion-style timeline: a sticky name column, a sticky date header, shaded weekends, a today line and one row
// per item with a bar from start to end. Only this box scrolls (both ways, capped to the viewport height so the
// header can stick), never the page. On phones the name column shrinks to the icon; the bar carries the title.
export function TimelineView({ items, range }: { items: CalItem[]; range: { from: string; to: string } }) {
    const t = useT();
    const locale = useLocale();
    const days = daysOf(range.from, range.to);
    const rows = timelineRows(items, range.from, range.to);
    const todayIdx = days.indexOf(ymd(new Date()));
    const box = useRef<HTMLDivElement>(null);
    const todayCell = useRef<HTMLDivElement>(null);

    // Centre today in the box when it's in the window (a month zoom on a phone shows only ~7 days, so today
    // would otherwise usually start off-screen). Horizontal only: never moves the page.
    useEffect(() => {
        const b = box.current;
        const c = todayCell.current;
        if (!b || !c) return;
        const br = b.getBoundingClientRect();
        const cr = c.getBoundingClientRect();
        b.scrollLeft += cr.left + cr.width / 2 - (br.left + br.width / 2);
    }, [range.from, todayIdx]);

    return (
        <div ref={box} className="bg-surface-card max-h-[calc(100dvh-12rem)] max-w-full overflow-auto rounded-2xl border">
            <div className="flex w-max min-w-full">
                {/* Name column: sticks to the left while the dates scroll under it. */}
                <div className="bg-surface-card sticky left-0 z-20 w-12 shrink-0 border-r sm:w-56">
                    <div className="bg-surface-card sticky top-0 z-10 h-12 border-b" />
                    {rows.map(({ item }) => (
                        <Link
                            key={itemKey(item)}
                            href={item.href}
                            title={item.title}
                            className="hover:bg-surface flex h-11 items-center justify-center gap-2 border-b px-3 text-sm sm:justify-start"
                        >
                            <LucideIcon name={item.icon} className="text-primary size-4 shrink-0" />
                            <span className={`hidden truncate sm:inline ${item.done ? 'text-muted-foreground line-through' : ''}`}>{item.title}</span>
                        </Link>
                    ))}
                </div>

                {/* Track: day columns + bars, positioned in % of its width. */}
                <div className="relative flex-1" style={{ minWidth: days.length * COL }}>
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
                    {rows.map(({ item, offset, span }) => (
                        <div key={itemKey(item)} className="relative h-11 overflow-hidden border-b">
                            <Link
                                href={item.href}
                                title={item.title}
                                className={`absolute top-1.5 flex h-8 items-center gap-1.5 overflow-hidden rounded-lg px-2 text-sm font-medium whitespace-nowrap shadow-xs hover:brightness-95 ${itemTone(item.color)} ${item.done ? 'line-through opacity-60' : ''}`}
                                style={{ left: `calc(${pct(offset, days.length)} + 2px)`, width: `calc(${pct(span, days.length)} - 4px)` }}
                            >
                                <LucideIcon name={item.icon} className="size-4 shrink-0" />
                                {span >= SHORT && <span className="truncate">{item.title}</span>}
                            </Link>
                            {/* Gantt-style outside label for short bars; the row clips it at the window's edge. */}
                            {span < SHORT && (
                                <span
                                    className={`text-foreground pointer-events-none absolute top-1.5 flex h-8 items-center pl-1.5 text-sm whitespace-nowrap ${item.done ? 'text-muted-foreground line-through' : ''}`}
                                    style={{ left: pct(offset + span, days.length) }}
                                >
                                    {item.title}
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
