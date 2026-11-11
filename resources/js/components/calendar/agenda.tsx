import { Link } from '@inertiajs/react';
import { Check, StickyNote } from 'lucide-react';
import { useRef } from 'react';

import { MarkPill } from '@/components/calendar/event-days';
import { LucideIcon } from '@/components/lucide-icon';
import { addDays, daysBetween, itemKey, itemsOn, parseDay, type CalItem } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

// The calendar's agenda pieces (design j6QDy4 04, C1): one row per thing, each source in its own shape (events are
// a coloured bar, to-dos a circle, birthdays a cake, cars a wrench), the week strip on top, days listed below.

// An event's colour as a solid stripe (the bar left of its title, the timeline's bar edge).
const STRIPE: Record<string, string> = {
    green: 'bg-emerald-600',
    amber: 'bg-amber-500',
    yellow: 'bg-amber-400',
    red: 'bg-red-500',
    blue: 'bg-blue-600',
    purple: 'bg-purple-500',
    sand: 'bg-stone-400',
    teal: 'bg-teal-500', // the category-only colours (CalendarCategory::COLORS), see lib/tasks EXTRA_TONES
    pink: 'bg-pink-500',
};
export const stripe = (color: string | null) => (color && STRIPE[color]) || 'bg-primary';

// Things that take a day (design 04b: trips, fasts, holidays, school breaks, all-day family days): all-day calendar
// events. A timed thing is a dot, a to-do a circle. Birthdays stay cake rows in the agenda and dots in Month: a
// family with every contact's birthday in (the demo has 366) would bury the month and the timeline in them.
export const takesADay = (i: CalItem) => !i.startTime && i.source === 'calendar';
const multiDay = (i: CalItem) => i.end > i.start;

// 17:30 → "17:30", 09:05 → "9:05" (as the design writes times; the server sends HH:MM).
const time = (t: string) => t.replace(/^0(\d)/, '$1');
const weekday = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(appLocale(), o);

// One thing on a day (the agenda, a tapped day under Month and Timeline): its time (or "due" / "all day") on the
// left, its shape, its title and a line saying who / where / which day of how many.
export function AgendaRow({ item, day, onToggle }: { item: CalItem; day: string; onToggle?: (i: CalItem) => void }) {
    const t = useT();
    const total = daysBetween(item.start, item.end) + 1;
    const nth = daysBetween(item.start, day) + 1;
    const span = total > 1 ? (nth === total ? t('calendar.last_day_of', { total }) : t('calendar.day_of_cap', { n: nth, total })) : null;
    const who = item.people.map((p) => p.name.split(' ')[0]).join(', ');
    // A multi-day event's card also says what this day holds (design SMMOk): its open to-dos in the meta line, then its
    // note and its marks. The server sends them by day index from the occurrence's start (CalendarEvent::occurrenceDays).
    const idx = nth - 1;
    const dayInfo = item.days;
    const openTodos = dayInfo?.todos[idx] ?? 0;
    const note = dayInfo?.notes[idx];
    const marks = dayInfo?.marks.filter((m) => m.days.includes(idx)) ?? [];
    const sub = [span, openTodos > 0 ? t('calendar.todo_count', { count: openTodos }) : null, item.sub, who].filter(Boolean).join(' · ');
    const isTask = item.kind === 'task';
    const left = item.startTime ? time(item.startTime) : isTask ? t('calendar.due') : t('calendar.all_day_short');
    const shape = isTask ? (
        <button
            type="button"
            onClick={(e) => {
                e.preventDefault();
                onToggle?.(item);
            }}
            aria-label={item.done ? t('tasks.mark_not_done') : t('tasks.mark_done')}
            className={`flex size-6 shrink-0 items-center justify-center rounded-full border-2 ${item.done ? 'border-primary bg-primary text-primary-foreground' : 'border-primary'}`}
        >
            {item.done && <Check className="size-3.5" />}
        </button>
    ) : item.source === 'calendar' ? (
        <span className={`h-9 w-1 shrink-0 rounded-full ${stripe(item.color)}`} aria-hidden />
    ) : (
        <span className="bg-surface text-primary flex size-7 shrink-0 items-center justify-center rounded-lg" aria-hidden>
            <LucideIcon name={item.icon} className="size-4" />
        </span>
    );
    return (
        <Link
            // A multi-day event opens on the day that was tapped (its day sheet), not just the event.
            href={dayInfo || (total > 1 && item.kind === 'event' && item.source === 'calendar') ? `${item.href}?day=${day}` : item.href}
            // A multi-day all-day thing is tinted, so "today is part of the trip" reads at a glance (C5).
            className={`flex items-center gap-3 px-4 py-3 ${total > 1 && !item.startTime && item.color ? itemTone(item.color).split(' ')[0] : ''}`}
        >
            <span className="text-primary w-16 shrink-0 font-mono text-[13px] whitespace-nowrap">{left}</span>
            <span className="flex w-6 shrink-0 justify-center">{shape}</span>
            <span className="min-w-0 flex-1">
                <span className={`block truncate text-base font-semibold ${item.done ? 'text-muted-foreground line-through' : 'text-primary'}`}>
                    {item.title}
                </span>
                {sub && <span className="text-muted-foreground block truncate text-sm">{sub}</span>}
                {note && (
                    <span className="text-ink-2 mt-1 flex items-start gap-1.5 text-sm">
                        <StickyNote className="text-muted-foreground mt-0.5 size-3.5 shrink-0" />
                        <span className="line-clamp-2 min-w-0">{note}</span>
                    </span>
                )}
                {marks.length > 0 && (
                    <span className="mt-1.5 flex flex-wrap gap-1.5">
                        {marks.map((m) => (
                            <MarkPill key={m.id} mark={m} />
                        ))}
                    </span>
                )}
            </span>
        </Link>
    );
}

// "Today Tue 20" / "Thursday Oct 22 · 2 things": a day's heading over its rows.
export function DayHeading({ day, today, count }: { day: string; today: string; count?: number }) {
    const t = useT();
    return (
        <h2 className="flex items-baseline gap-2 px-1">
            <span className="font-heading text-primary text-lg font-bold first-letter:uppercase">
                {day === today ? t('calendar.today') : weekday(day, { weekday: 'long' })}
            </span>
            <span className="text-muted-foreground text-sm">
                {day === today ? `${weekday(day, { weekday: 'short' })} ${Number(day.slice(8))}` : weekday(day, { month: 'short', day: 'numeric' })}
                {count !== undefined && ` · ${t('calendar.things', { count })}`}
            </span>
        </h2>
    );
}

// A day's rows in one card (or "nothing" when empty).
export function DayList({
    items,
    day,
    today,
    onToggle,
    count = false,
}: {
    items: CalItem[];
    day: string;
    today: string;
    onToggle?: (i: CalItem) => void;
    count?: boolean;
}) {
    const t = useT();
    const rows = itemsOn(items, day).sort(
        (a, b) =>
            (a.startTime ? 1 : 0) - (b.startTime ? 1 : 0) || (a.startTime ?? '').localeCompare(b.startTime ?? '') || a.title.localeCompare(b.title),
    );
    return (
        <section className="flex flex-col gap-2">
            <DayHeading day={day} today={today} count={count ? rows.length : undefined} />
            {rows.length ? (
                <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                    {rows.map((i) => (
                        <AgendaRow key={itemKey(i)} item={i} day={day} onToggle={onToggle} />
                    ))}
                </div>
            ) : (
                <p className="text-muted-foreground px-1 text-sm">{t('calendar.nothing')}</p>
            )}
        </section>
    );
}

// The week strip (C1): weekday letter, date, dots for how busy (up to 3); today is the sun-yellow tile, the picked day
// inked. Under it, a band for each multi-day thing crossing the week ("Lake Tahoe trip · until Sun"). Swipe it
// sideways for the previous / next week.
export function WeekStrip({
    days,
    items,
    today,
    selected,
    onSelect,
    onSwipe,
}: {
    days: string[];
    items: CalItem[];
    today: string;
    selected: string;
    onSelect: (d: string) => void;
    onSwipe: (dir: 1 | -1) => void;
}) {
    const t = useT();
    const start = useRef<number | null>(null);
    const first = days[0];
    const last = days[days.length - 1];
    const bands = items.filter((i) => takesADay(i) && multiDay(i) && i.start <= last && i.end >= first).slice(0, 2);
    return (
        <div
            className="bg-surface-card touch-pan-y rounded-2xl border px-2 pt-3 pb-2"
            onPointerDown={(e) => (start.current = e.clientX)}
            onPointerUp={(e) => {
                const dx = start.current === null ? 0 : e.clientX - start.current;
                start.current = null;
                if (Math.abs(dx) > 60) onSwipe(dx < 0 ? 1 : -1);
            }}
        >
            <div className="grid grid-cols-7 gap-1">
                {days.map((d) => {
                    const n = Math.min(3, itemsOn(items, d).length);
                    const isToday = d === today;
                    const on = d === selected;
                    return (
                        <button
                            key={d}
                            type="button"
                            onClick={() => onSelect(d)}
                            aria-pressed={on}
                            aria-label={weekday(d, { weekday: 'long', day: 'numeric', month: 'long' })}
                            className={`flex flex-col items-center gap-1 rounded-xl py-1.5 ${isToday ? 'bg-accent-strong text-accent-strong-foreground' : on ? 'ring-primary ring-2' : ''}`}
                        >
                            <span className="text-muted-foreground font-mono text-xs font-semibold uppercase">
                                {weekday(d, { weekday: 'narrow' })}
                            </span>
                            <span className="text-primary text-xl font-bold tabular-nums">{Number(d.slice(8))}</span>
                            <span className="flex h-1.5 gap-0.5" aria-hidden>
                                {Array.from({ length: n }, (_, k) => (
                                    <span key={k} className="bg-primary size-1.5 rounded-full" />
                                ))}
                            </span>
                        </button>
                    );
                })}
            </div>
            {bands.map((b) => {
                const s = b.start < first ? first : b.start;
                const e = b.end > last ? last : b.end;
                return (
                    <Link
                        key={itemKey(b)}
                        href={b.href}
                        // The band covers its days; a short one's words carry on past it rather than being cut.
                        className={`mt-1.5 flex h-6 items-center gap-1.5 rounded-md px-2 text-xs font-semibold whitespace-nowrap ${itemTone(b.color)}`}
                        style={{ marginLeft: `calc(${daysBetween(first, s)} * 100% / 7)`, width: `calc(${daysBetween(s, e) + 1} * 100% / 7)` }}
                    >
                        <LucideIcon name={b.icon} className="size-3.5 shrink-0" />
                        <span className={daysBetween(s, e) >= 3 ? 'truncate' : 'overflow-visible'}>
                            {b.title} ·{' '}
                            {t('calendar.until_day', {
                                day: b.end <= last ? weekday(b.end, { weekday: 'short' }) : weekday(b.end, { month: 'short', day: 'numeric' }),
                            })}
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}

// The agenda (C1): the week strip, then each day from the picked one to Sunday that has something, in order.
export function AgendaView({
    items,
    range,
    today,
    selected,
    onSelect,
    onWeek,
    onToggle,
}: {
    items: CalItem[];
    range: { from: string; to: string };
    today: string;
    selected: string;
    onSelect: (d: string) => void;
    onWeek: (dir: 1 | -1) => void;
    onToggle: (i: CalItem) => void;
}) {
    const t = useT();
    const days = Array.from({ length: 7 }, (_, k) => addDays(range.from, k));
    const from = selected >= range.from && selected <= range.to ? selected : range.from;
    const listed = days.filter((d) => d >= from && itemsOn(items, d).length > 0);
    return (
        <div className="flex flex-col gap-5">
            <WeekStrip days={days} items={items} today={today} selected={selected} onSelect={onSelect} onSwipe={onWeek} />
            {listed.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">{t('calendar.nothing_week')}</p>}
            {listed.map((d) => (
                <DayList key={d} items={items} day={d} today={today} onToggle={onToggle} />
            ))}
        </div>
    );
}
