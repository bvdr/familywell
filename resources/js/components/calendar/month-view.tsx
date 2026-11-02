import { Link } from '@inertiajs/react';

import { DayList, stripe, takesADay } from '@/components/calendar/agenda';
import { useIsMobile } from '@/hooks/use-mobile';
import { itemKey, itemsOn, layoutWeek, weeksOf, type CalItem } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

const LANE_PX = 22; // bar height (20) + gap

// Month (design j6QDy4 C2 / C5): the regular grid. Anything that takes a day is a labelled bar across its days and
// carries on into the next week with its name repeated; timed things and to-dos stay as dots. Bars get two lanes a
// week on a phone (three on desktop); the rest become "+1 more". Tap a day and its agenda sits under the grid, so the
// month never goes away. Today is the sun-yellow circle, the picked day inked.
export function MonthView({
    items,
    range,
    date,
    today,
    selected,
    onSelect,
    onToggle,
}: {
    items: CalItem[];
    range: { from: string; to: string };
    date: string;
    today: string;
    selected: string;
    onSelect: (day: string) => void;
    onToggle: (i: CalItem) => void;
}) {
    const t = useT();
    const locale = useLocale();
    const lanes = useIsMobile() ? 2 : 3;
    const weeks = weeksOf(range.from, range.to);
    const month = date.slice(0, 7);
    const weekdays = weeks[0].map((d) => new Date(d + 'T12:00').toLocaleDateString(locale, { weekday: 'narrow' }));
    const big = items.filter(takesADay);
    const small = items.filter((i) => !takesADay(i));

    return (
        <div className="flex flex-col gap-5">
            <div className="bg-surface-card overflow-hidden rounded-2xl border">
                <div className="text-muted-foreground grid grid-cols-7 pt-2 text-center font-mono text-xs font-semibold uppercase">
                    {weekdays.map((w, i) => (
                        <div key={i} className="py-1">
                            {w}
                        </div>
                    ))}
                </div>
                {weeks.map((week) => {
                    const placed = layoutWeek(big, week);
                    const shownLanes = Math.min(
                        lanes,
                        placed.reduce((m, p) => Math.max(m, p.lane + 1), 0),
                    );
                    return (
                        <div key={week[0]} className="border-border/60 relative border-b last:border-b-0">
                            <div className="grid grid-cols-7">
                                {week.map((d) => {
                                    const outside = d.slice(0, 7) !== month;
                                    const dots = small.filter((i) => i.start <= d && i.end >= d).slice(0, 3);
                                    const on = d === selected && d !== today;
                                    return (
                                        <button
                                            key={d}
                                            type="button"
                                            onClick={() => onSelect(d)}
                                            aria-pressed={d === selected}
                                            aria-label={new Date(d + 'T12:00').toLocaleDateString(locale, { day: 'numeric', month: 'long' })}
                                            className="flex flex-col items-center gap-0.5 pt-1.5 pb-1"
                                        >
                                            <span
                                                className={`flex size-8 items-center justify-center rounded-full text-base tabular-nums ${
                                                    d === today
                                                        ? 'bg-accent-strong text-accent-strong-foreground font-bold'
                                                        : on
                                                          ? 'bg-primary text-primary-foreground font-bold'
                                                          : outside
                                                            ? 'text-muted-foreground/50'
                                                            : 'text-primary'
                                                }`}
                                            >
                                                {Number(d.slice(8))}
                                            </span>
                                            <span className="flex h-1.5 gap-0.5" aria-hidden>
                                                {dots.map((i) => (
                                                    <span key={itemKey(i)} className={`size-1.5 rounded-full ${stripe(i.color)}`} />
                                                ))}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            {/* The bars, each week's piece of a thing, its name on every piece. */}
                            {shownLanes > 0 && (
                                <div className="relative mb-1.5" style={{ height: shownLanes * LANE_PX }}>
                                    {placed
                                        .filter((p) => p.lane < lanes)
                                        .map((p) => {
                                            const starts = p.item.start >= week[0];
                                            const ends = p.item.end <= week[6];
                                            return (
                                                <Link
                                                    key={itemKey(p.item)}
                                                    href={p.item.href}
                                                    title={p.item.title}
                                                    className={`absolute flex h-5 items-center overflow-hidden px-1.5 text-[11px] font-semibold whitespace-nowrap ${itemTone(p.item.color)} ${starts ? 'rounded-l-md' : ''} ${ends ? 'rounded-r-md' : ''}`}
                                                    style={{
                                                        left: `calc(${p.col} * 100% / 7 + ${starts ? 3 : 0}px)`,
                                                        width: `calc(${p.span} * 100% / 7 - ${(starts ? 3 : 0) + (ends ? 3 : 0)}px)`,
                                                        top: p.lane * LANE_PX,
                                                    }}
                                                >
                                                    {starts && (
                                                        <span
                                                            className={`mr-1 h-3 w-0.5 shrink-0 rounded-full ${stripe(p.item.color)}`}
                                                            aria-hidden
                                                        />
                                                    )}
                                                    <span className="truncate">{p.item.title}</span>
                                                </Link>
                                            );
                                        })}
                                </div>
                            )}
                            {/* "+N more" under the days whose bars didn't fit. */}
                            {placed.some((p) => p.lane >= lanes) && (
                                <div className="grid grid-cols-7 pb-1">
                                    {week.map((d, col) => {
                                        const hidden = placed.filter((p) => p.lane >= lanes && p.col <= col && p.col + p.span > col).length;
                                        return (
                                            <button
                                                key={d}
                                                type="button"
                                                onClick={() => onSelect(d)}
                                                className="text-muted-foreground font-mono text-[10px] font-semibold"
                                            >
                                                {hidden > 0 && t('calendar.more', { count: hidden })}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {itemsOn(items, selected) && <DayList items={items} day={selected} today={today} onToggle={onToggle} count />}
        </div>
    );
}
