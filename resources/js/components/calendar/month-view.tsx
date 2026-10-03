import { Link } from '@inertiajs/react';

import { LucideIcon } from '@/components/lucide-icon';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/hooks/use-mobile';
import { daysBetween, itemKey, itemsOn, layoutWeek, weeksOf, ymd, type CalItem } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

const LANES = 3; // bars per week row on desktop before "+N more"
const LANE_PX = 22; // bar height (20) + gap

// Day number circle: the phone's selected day is solid (it drives the list below), today is a pale well.
const dayTone = (selected: boolean, today: boolean) =>
    selected ? 'bg-primary text-primary-foreground font-semibold' : today ? 'bg-accent-subtle text-primary font-semibold' : '';

// Classic month grid over the server's 6-week range. Desktop: each week row lays multi-day items as bars across
// their days (layoutWeek lanes), caps at LANES with "+N more", and tapping a day opens the add sheet for it.
// Phone: a dot under each busy day, tapping selects it and its items are listed below the grid (tiny bars are
// unreadable at 360px).
export function MonthView({
    items,
    range,
    date,
    selected,
    onSelect,
    onAdd,
}: {
    items: CalItem[];
    range: { from: string; to: string };
    date: string;
    selected: string;
    onSelect: (day: string) => void;
    onAdd: (day: string) => void;
}) {
    const t = useT();
    const locale = useLocale();
    const isMobile = useIsMobile();
    const weeks = weeksOf(range.from, range.to);
    const month = date.slice(0, 7);
    const today = ymd(new Date());
    const weekdays = weeks[0].map((d) => new Date(d + 'T12:00').toLocaleDateString(locale, { weekday: 'short' }));
    const dayItems = itemsOn(items, selected);

    return (
        <div className="flex flex-col gap-4">
            <div className="bg-surface-card overflow-hidden rounded-2xl border">
                <div className="text-muted-foreground grid grid-cols-7 border-b text-center text-xs font-medium tracking-wide uppercase">
                    {weekdays.map((w, i) => (
                        <div key={i} className="py-2">
                            {w}
                        </div>
                    ))}
                </div>
                {weeks.map((week) => {
                    const placed = layoutWeek(items, week);
                    return (
                        <div key={week[0]} className="relative grid grid-cols-7 border-b last:border-b-0">
                            {week.map((d, col) => {
                                const busy = placed.some((p) => p.col <= col && p.col + p.span > col);
                                const outside = d.slice(0, 7) !== month;
                                return (
                                    <button
                                        key={d}
                                        type="button"
                                        onClick={() => (isMobile ? onSelect(d) : onAdd(d))}
                                        aria-label={new Date(d + 'T12:00').toLocaleDateString(locale, { day: 'numeric', month: 'long' })}
                                        aria-pressed={isMobile ? d === selected : undefined}
                                        className={`hover:bg-surface/60 relative flex min-h-14 flex-col items-center border-r p-1 text-sm transition-colors last:border-r-0 md:min-h-32 md:items-start ${outside ? 'text-muted-foreground/50' : 'text-foreground'}`}
                                    >
                                        <span
                                            className={`flex size-7 items-center justify-center rounded-full tabular-nums ${dayTone(isMobile && d === selected, d === today)}`}
                                        >
                                            {Number(d.slice(8))}
                                        </span>
                                        {busy && <span className="bg-primary mt-1 size-1.5 rounded-full md:hidden" aria-hidden />}
                                    </button>
                                );
                            })}
                            {/* Bars (desktop only), positioned over the day cells by column + lane. The layer ignores
                                pointer events so empty space still hits the day cell underneath; the bars opt back in. */}
                            <div className="pointer-events-none absolute inset-x-0 top-9 hidden md:block">
                                {placed
                                    .filter((p) => p.lane < LANES)
                                    .map((p) => (
                                        <Link
                                            key={itemKey(p.item)}
                                            href={p.item.href}
                                            title={p.item.title}
                                            className={`pointer-events-auto absolute flex h-5 items-center gap-1 overflow-hidden rounded-md px-1.5 text-xs font-medium whitespace-nowrap hover:brightness-95 ${itemTone(p.item.color)} ${p.item.done ? 'line-through opacity-60' : ''}`}
                                            style={{
                                                left: `calc(${p.col} * 100% / 7 + 3px)`,
                                                width: `calc(${p.span} * 100% / 7 - 6px)`,
                                                top: p.lane * LANE_PX,
                                            }}
                                        >
                                            <LucideIcon name={p.item.icon} className="size-3 shrink-0" />
                                            {p.item.startTime && <span className="tabular-nums opacity-70">{p.item.startTime}</span>}
                                            <span className="truncate">{p.item.title}</span>
                                        </Link>
                                    ))}
                            </div>
                            {/* "+N more" (desktop): its own trigger, a sibling of the day cells rather than inside one
                                (no button-in-button, and tapping it must not open the add sheet). Opens that day's full
                                list, so items past the lane cap stay reachable. */}
                            {week.map((d, col) => {
                                const hidden = placed.filter((p) => p.lane >= LANES && p.col <= col && p.col + p.span > col).length;
                                return (
                                    hidden > 0 && (
                                        <DropdownMenu key={d}>
                                            <DropdownMenuTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="text-muted-foreground hover:text-primary absolute bottom-1 hidden rounded px-1.5 text-[11px] font-medium md:block"
                                                    style={{ left: `calc(${col} * 100% / 7 + 1px)` }}
                                                >
                                                    {t('calendar.more', { count: hidden })}
                                                </button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="start" className="flex w-72 flex-col gap-1.5 p-2">
                                                <p className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">
                                                    {new Date(d + 'T12:00').toLocaleDateString(locale, {
                                                        weekday: 'long',
                                                        day: 'numeric',
                                                        month: 'long',
                                                    })}
                                                </p>
                                                {itemsOn(items, d).map((i) => (
                                                    <AgendaItem key={itemKey(i)} item={i} day={d} />
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )
                                );
                            })}
                        </div>
                    );
                })}
            </div>

            {/* Selected day's items (phones; desktop shows them as bars in the grid). */}
            <div className="flex flex-col gap-2 md:hidden">
                <h2 className="font-heading text-primary text-lg first-letter:uppercase">
                    {new Date(selected + 'T12:00').toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long' })}
                </h2>
                {dayItems.length === 0 && <p className="text-muted-foreground text-sm">{t('calendar.nothing')}</p>}
                {dayItems.map((i) => (
                    <AgendaItem key={itemKey(i)} item={i} day={selected} />
                ))}
            </div>
        </div>
    );
}

export function AgendaItem({ item, day }: { item: CalItem; day: string }) {
    const t = useT();
    const total = daysBetween(item.start, item.end) + 1;
    const sub = item.startTime
        ? `${item.startTime}–${item.endTime}`
        : total > 1
          ? t('calendar.day_of', { n: daysBetween(item.start, day) + 1, total })
          : t('calendar.all_day');
    return (
        <Link href={item.href} className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-3 transition-colors">
            <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${itemTone(item.color)}`}>
                <LucideIcon name={item.icon} className="size-5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className={`block truncate font-medium ${item.done ? 'text-muted-foreground line-through' : 'text-primary'}`}>
                    {item.title}
                </span>
                <span className="text-muted-foreground block truncate text-xs">
                    {[sub, item.people.map((p) => p.name).join(', ')].filter(Boolean).join(' · ')}
                </span>
            </span>
        </Link>
    );
}
