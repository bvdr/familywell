import { ChevronRight, Highlighter, MessageSquarePlus } from 'lucide-react';
import type { ReactNode } from 'react';

import { LucideIcon } from '@/components/lucide-icon';
import { type SubTask, type TaskNode } from '@/components/tasks/task-tree';
import { addDays, daysBetween, parseDay } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';

// "Days inside an event" (design C15): a multi-day event's page lists every one of its days, so a 40-day fast has
// its 40 days one tap away. Marks (a fish for a dezlegare day) are stored by day INDEX from the event's start, so
// they carry to next year's run; a day's note is about its date; a day's to-dos are the event's own to-dos that are
// due that date (no second to-do system).

// A mark: its days are indexes counted from the event's first day (0 = day 1).
// The most marks an event can carry (CalendarEventMark::MAX_PER_EVENT; the server enforces it).
export const MARK_LIMIT = 12;

export type DayMark = { id: number; name: string; icon: string | null; days: number[] };

// What the server sends for the occurrence on screen (CalendarController::daysPayload).
export type EventDays = {
    start: string;
    end: string;
    marks: DayMark[];
    notes: Record<string, string>; // by date
    inside: { id: number; title: string; date: string; startTime: string | null; href: string }[];
};

/** The event's to-dos (top-level and sub-tasks) due on `date`. */
export const todosOn = (tasks: TaskNode[], date: string): SubTask[] => tasks.flatMap((x) => [x, ...x.subtasks]).filter((x) => x.dueDate === date);

export const marksOn = (marks: DayMark[], idx: number) => marks.filter((m) => m.days.includes(idx));

const dateText = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(appLocale(), o);

// One mark as the design's pill (sun-soft fill, its icon, its name): the day card, the "coming up inside" rows.
export function MarkPill({ mark }: { mark: Pick<DayMark, 'name' | 'icon'> }) {
    return (
        <span className="bg-sun-soft text-ink inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold">
            <LucideIcon name={mark.icon ?? 'tag'} className="size-3.5 shrink-0" />
            <span className="truncate">{mark.name}</span>
        </span>
    );
}

// The grid of the event's days in Monday-first week rows, days outside the event blank. Shared by the event page
// (tap = open the day) and the Mark days sheet (tap = toggle), which differ only in what a cell holds.
export function DayGrid({
    start,
    end,
    today,
    onPick,
    cell,
    label,
}: {
    start: string;
    end: string;
    today?: string;
    onPick: (idx: number, date: string) => void;
    cell: (idx: number, date: string) => { body: ReactNode; on?: boolean };
    label: (idx: number, date: string) => string;
}) {
    const total = daysBetween(start, end) + 1;
    const lead = (parseDay(start).getDay() + 6) % 7; // blanks before the first day: Monday-first
    const slots: (number | null)[] = [...Array<null>(lead).fill(null), ...Array.from({ length: total }, (_, k) => k)];
    while (slots.length % 7) slots.push(null);
    // Weekday letters from a known Monday so the header follows the app's language.
    const letters = Array.from({ length: 7 }, (_, k) => dateText(addDays('2026-01-05', k), { weekday: 'narrow' }));

    return (
        <div className="bg-surface-card rounded-2xl border p-2">
            <div className="grid grid-cols-7 gap-1 pb-1" aria-hidden>
                {letters.map((l, k) => (
                    <span key={k} className="text-muted-foreground text-center font-mono text-xs font-semibold uppercase">
                        {l}
                    </span>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-1">
                {slots.map((idx, k) => {
                    if (idx === null) return <span key={`o${k}`} />;
                    const date = addDays(start, idx);
                    const { body, on } = cell(idx, date);
                    const isToday = date === today;
                    return (
                        <button
                            key={idx}
                            type="button"
                            onClick={() => onPick(idx, date)}
                            aria-label={label(idx, date)}
                            aria-pressed={on}
                            className={`flex min-h-14 min-w-0 flex-col items-center gap-0.5 rounded-xl px-0.5 py-1.5 ${isToday ? 'bg-primary text-primary-foreground' : on ? 'bg-sun-soft text-ink ring-primary ring-2' : 'bg-surface text-primary'}`}
                        >
                            <span className="text-base leading-none font-bold tabular-nums">
                                {date.slice(8).replace(/^0/, '')}
                                {/* The month name where it changes, so "1" after "30" reads as the new month. */}
                                {(idx === 0 || date.endsWith('-01')) && (
                                    <span className="block text-[10px] leading-tight font-semibold uppercase">
                                        {dateText(date, { month: 'short' })}
                                    </span>
                                )}
                            </span>
                            {body}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

// The event page's days section: the grid with its marks and dots, the legend, "Coming up inside" and the composer.
export function DaysSection({
    days,
    tasks,
    today,
    canEdit,
    markLimit,
    onOpenDay,
    onMarkDays,
    onEditMark,
}: {
    days: EventDays;
    tasks: TaskNode[];
    today: string;
    canEdit: boolean;
    markLimit: boolean; // the event is at its most marks: no more to add
    onOpenDay: (date: string) => void;
    onMarkDays: () => void;
    onEditMark: (m: DayMark) => void;
}) {
    const t = useT();
    const total = daysBetween(days.start, days.end) + 1;
    const hasNote = (date: string) => !!days.notes[date];
    const hasTodo = (date: string) => todosOn(tasks, date).length > 0;
    const composerDay = today < days.start ? days.start : today > days.end ? days.end : today;

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-3 px-1">
                <h2 className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                    {t('calendar.the_days', { total })}
                </h2>
                {canEdit && (
                    <button
                        type="button"
                        onClick={onMarkDays}
                        disabled={markLimit}
                        title={markLimit ? t('calendar.mark_limit', { max: MARK_LIMIT }) : undefined}
                        className="bg-surface text-primary inline-flex min-h-9 items-center gap-1.5 rounded-full px-3.5 text-sm font-semibold disabled:opacity-50"
                    >
                        <Highlighter className="size-4" />
                        {t('calendar.mark_days')}
                    </button>
                )}
            </div>

            <DayGrid
                start={days.start}
                end={days.end}
                today={today}
                onPick={(_, date) => onOpenDay(date)}
                label={(idx, date) =>
                    `${dateText(date, { weekday: 'long', day: 'numeric', month: 'long' })} · ${t('calendar.day_of_cap', { n: idx + 1, total })}`
                }
                cell={(idx, date) => {
                    const marks = marksOn(days.marks, idx);
                    const dot = hasNote(date) || hasTodo(date);
                    return {
                        // Up to 2 mark icons and a dot for a note or to-do: a day never grows past its row.
                        body: (
                            <span className="flex h-4 items-center justify-center gap-0.5" aria-hidden>
                                {marks.slice(0, 2).map((m) => (
                                    <LucideIcon key={m.id} name={m.icon ?? 'tag'} className="size-3.5" />
                                ))}
                                {dot && <span className="size-1.5 rounded-full bg-current" />}
                            </span>
                        ),
                    };
                }}
            />

            {/* Legend: each mark with how many days it covers (tap one to edit it), and what the dot means. */}
            <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1.5 px-1 text-sm">
                {days.marks.map((m) => (
                    <button
                        key={m.id}
                        type="button"
                        onClick={() => onEditMark(m)}
                        disabled={!canEdit}
                        className="text-primary inline-flex min-h-8 max-w-full items-center gap-1.5 text-left"
                    >
                        <LucideIcon name={m.icon ?? 'tag'} className="size-4 shrink-0" />
                        <span className="truncate">
                            {m.name} · {t('calendar.days', { count: m.days.filter((i) => i < total).length })}
                        </span>
                    </button>
                ))}
                <span className="inline-flex min-h-8 items-center gap-1.5">
                    <span className="bg-primary size-1.5 rounded-full" />
                    {t('calendar.note_or_todo')}
                </span>
            </div>

            {days.inside.length > 0 && (
                <div className="flex flex-col gap-2 pt-2">
                    <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('calendar.coming_inside')}
                    </h2>
                    <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                        {days.inside.map((r) => {
                            const idx = daysBetween(days.start, r.date);
                            const marks = marksOn(days.marks, idx);
                            const open = todosOn(tasks, r.date).filter((x) => !x.done).length;
                            return (
                                <button
                                    key={`${r.id}-${r.date}`}
                                    type="button"
                                    onClick={() => onOpenDay(r.date)}
                                    className="flex w-full items-center gap-3 px-4 py-3 text-left"
                                >
                                    <span className="w-14 shrink-0 font-mono text-xs font-semibold uppercase">
                                        <span className="text-primary block">
                                            {dateText(r.date, { weekday: 'short' })} {r.date.slice(8).replace(/^0/, '')}
                                        </span>
                                        <span className="text-muted-foreground block">{t('calendar.day_short', { n: idx + 1 })}</span>
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block truncate text-base font-semibold">{r.title}</span>
                                        {(marks.length > 0 || open > 0) && (
                                            <span className="mt-1 flex flex-wrap gap-1.5">
                                                {marks.map((m) => (
                                                    <MarkPill key={m.id} mark={m} />
                                                ))}
                                                {open > 0 && (
                                                    <span className="bg-surface text-primary inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold">
                                                        {t('calendar.todo_count', { count: open })}
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </span>
                                    <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* The design's composer dock, in the page's flow rather than fixed: a fixed bar would sit on the app's
                tab bar. It opens today's day (or the nearest end) on its to-do field. */}
            {canEdit && (
                <button
                    type="button"
                    onClick={() => onOpenDay(composerDay)}
                    className="bg-surface-card text-muted-foreground flex min-h-12 w-full items-center gap-3 rounded-full border px-4 text-left"
                >
                    <MessageSquarePlus className="size-5 shrink-0" />
                    <span className="truncate">{t('calendar.day_composer', { n: daysBetween(days.start, composerDay) + 1 })}</span>
                </button>
            )}
        </section>
    );
}
