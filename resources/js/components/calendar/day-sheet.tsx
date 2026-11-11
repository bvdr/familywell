import { Check, ChevronLeft, ChevronRight, Plus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { type DayMark, type EventDays, todosOn } from '@/components/calendar/event-days';
import { LucideIcon } from '@/components/lucide-icon';
import { type SubTask, type TaskNode } from '@/components/tasks/task-tree';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Textarea } from '@/components/ui/textarea';
import { useOptimistic } from '@/hooks/use-optimistic';
import { addDays, daysBetween, parseDay } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { save } from '@/lib/save';

const label = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase';
const dateText = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(appLocale(), o);

// A day's note (C16). Saved when the field loses focus and again when the day changes or the sheet closes: on iOS a
// tap on an arrow or Done doesn't always blur the field first, and a note typed then lost is the worst outcome here.
function NoteField({ url, initial, readOnly }: { url: string; initial: string; readOnly: boolean }) {
    const t = useT();
    const [value, setValue] = useState(initial);
    const latest = useRef(initial);
    const saved = useRef(initial);
    // Unmount-time flush needs the newest text and URL without re-running the effect on every keystroke.
    const urlRef = useRef(url);
    urlRef.current = url;
    const flush = () => {
        if (latest.current === saved.current) return;
        saved.current = latest.current;
        void save('put', urlRef.current, { body: latest.current });
    };
    useEffect(() => flush, []); // flush only reads refs, so mount-once is right
    return (
        <Textarea
            value={value}
            readOnly={readOnly}
            maxLength={500}
            rows={3}
            aria-label={t('calendar.day_note')}
            placeholder={t('calendar.day_note_placeholder')}
            onChange={(e) => {
                latest.current = e.target.value;
                setValue(e.target.value);
            }}
            onBlur={flush}
            className="bg-surface-card"
        />
    );
}

// One day inside an event (design C16): its marks, its note and its to-dos. Marks, the note and the to-dos belong to
// this day only; the arrows step to the day before or after without closing the sheet.
export function DaySheet({
    open,
    onOpenChange,
    date,
    onDate,
    event,
    days,
    tasks,
    canEdit,
    onNewMark,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    date: string;
    onDate: (d: string) => void;
    event: { id: number; title: string };
    days: EventDays;
    tasks: TaskNode[];
    canEdit: boolean;
    onNewMark: (idx: number) => void;
}) {
    const t = useT();
    const total = daysBetween(days.start, days.end) + 1;
    const idx = Math.min(Math.max(daysBetween(days.start, date), 0), total - 1);
    const markDays = useOptimistic<number[]>();
    const doneOpt = useOptimistic<boolean>();
    const [picking, setPicking] = useState(false);
    const [title, setTitle] = useState('');
    const [adding, setAdding] = useState<{ key: number; title: string }[]>([]);

    // Marks and to-dos update on the tap and sync in the background (save() rolls back by reloading on failure).
    const marks = days.marks.map((m) => ({ ...m, days: markDays.get(m.id, m.days) }));
    const on = marks.filter((m) => m.days.includes(idx));
    const off = marks.filter((m) => !m.days.includes(idx));
    const setMark = (m: DayMark, add: boolean) => {
        const next = (add ? [...m.days, idx] : m.days.filter((i) => i !== idx)).sort((a, b) => a - b);
        markDays.set(m.id, next);
        void save('patch', `/m/calendar/events/${event.id}/marks/${m.id}`, { days: next }).then(() => markDays.clear(m.id));
    };
    const todos = todosOn(tasks, date);
    const isDone = (x: SubTask) => doneOpt.get(x.id, x.done);
    const toggle = (x: SubTask) => {
        if (!canEdit) return;
        const next = !isDone(x);
        doneOpt.set(x.id, next);
        void save('patch', `/m/calendar/tasks/${x.id}`, { done: next }).then(() => doneOpt.clear(x.id));
    };
    const add = () => {
        const v = title.trim();
        if (!v) return;
        const key = Date.now() + Math.random();
        setAdding((a) => [...a, { key, title: v }]);
        setTitle('');
        // The to-do is one of the event's own, dated to this day: that is the whole link between a day and its to-dos.
        void save('post', `/m/calendar/events/${event.id}/tasks`, { title: v, due_date: date }).then((r) => {
            setAdding((a) => a.filter((p) => p.key !== key));
            if (r !== 'ok') setTitle((c) => c || v);
        });
    };
    const step = (by: number) => {
        setPicking(false);
        onDate(addDays(date, by));
    };
    const arrow = 'bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-full disabled:opacity-40';
    const short = dateText(date, { month: 'short', day: 'numeric' });

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={dateText(date, { weekday: 'short', month: 'short', day: 'numeric' })}
            footer={
                <button
                    type="button"
                    onClick={() => onOpenChange(false)}
                    className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold"
                >
                    {t('calendar.day_done')}
                </button>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="flex items-center justify-between gap-2">
                    <button type="button" aria-label={t('calendar.prev_day')} disabled={idx === 0} onClick={() => step(-1)} className={arrow}>
                        <ChevronLeft className="size-5" />
                    </button>
                    <p className="text-primary min-w-0 truncate text-center font-mono text-xs font-semibold tracking-[0.1em] uppercase">
                        {t('calendar.day_of_cap', { n: idx + 1, total })} · {event.title}
                    </p>
                    <button type="button" aria-label={t('calendar.next_day')} disabled={idx === total - 1} onClick={() => step(1)} className={arrow}>
                        <ChevronRight className="size-5" />
                    </button>
                </div>

                {(on.length > 0 || canEdit) && (
                    <section className="flex flex-col gap-2">
                        <h3 className={label}>{t('calendar.marked')}</h3>
                        <div className="flex flex-wrap items-center gap-2">
                            {on.map((m) => (
                                <span
                                    key={m.id}
                                    className="bg-sun-soft text-ink inline-flex min-h-9 items-center gap-1.5 rounded-full pr-1.5 pl-3 text-sm font-semibold"
                                >
                                    <LucideIcon name={m.icon ?? 'tag'} className="size-4 shrink-0" />
                                    {m.name}
                                    {canEdit && (
                                        <button
                                            type="button"
                                            aria-label={t('calendar.remove_mark', { name: m.name })}
                                            onClick={() => setMark(m, false)}
                                            className="flex size-7 items-center justify-center rounded-full"
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    )}
                                </span>
                            ))}
                            {canEdit && (
                                <button
                                    type="button"
                                    onClick={() => (off.length ? setPicking((p) => !p) : onNewMark(idx))}
                                    aria-expanded={off.length ? picking : undefined}
                                    className="text-primary border-border inline-flex min-h-9 items-center gap-1 rounded-full border border-dashed px-3 text-sm font-semibold"
                                >
                                    <Plus className="size-4" />
                                    {t('calendar.add_mark')}
                                </button>
                            )}
                        </div>
                        {/* "+ Mark" opens the event's other marks to drop onto this day, or a new one (C17). */}
                        {picking && (
                            <div className="flex flex-wrap items-center gap-2">
                                {off.map((m) => (
                                    <button
                                        key={m.id}
                                        type="button"
                                        onClick={() => {
                                            setMark(m, true);
                                            setPicking(false);
                                        }}
                                        className="bg-surface text-primary inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 text-sm font-semibold"
                                    >
                                        <LucideIcon name={m.icon ?? 'tag'} className="size-4 shrink-0" />
                                        {m.name}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => onNewMark(idx)}
                                    className="text-primary inline-flex min-h-9 items-center rounded-full px-3 text-sm font-semibold underline underline-offset-4"
                                >
                                    {t('calendar.new_mark')}
                                </button>
                            </div>
                        )}
                    </section>
                )}

                {(canEdit || days.notes[date]) && (
                    <section className="flex flex-col gap-2">
                        <h3 className={label}>{t('calendar.day_note')}</h3>
                        {/* keyed by day: stepping to another day starts its own text (and saves this one first). */}
                        <NoteField
                            key={date}
                            url={`/m/calendar/events/${event.id}/days/${date}/note`}
                            initial={days.notes[date] ?? ''}
                            readOnly={!canEdit}
                        />
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <h3 className={label}>{t('calendar.day_todos')}</h3>
                    {(todos.length > 0 || adding.length > 0) && (
                        <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                            {todos.map((x) => (
                                <button
                                    key={x.id}
                                    type="button"
                                    onClick={() => toggle(x)}
                                    disabled={!canEdit}
                                    className="flex min-h-12 w-full items-center gap-3 px-4 py-2.5 text-left"
                                >
                                    <span
                                        className={`flex size-6 shrink-0 items-center justify-center rounded-full border-2 ${isDone(x) ? 'border-primary bg-primary text-primary-foreground' : 'border-primary'}`}
                                        aria-hidden
                                    >
                                        {isDone(x) && <Check className="size-3.5" />}
                                    </span>
                                    <span
                                        className={`min-w-0 flex-1 truncate text-base ${isDone(x) ? 'text-muted-foreground line-through' : 'text-primary'}`}
                                    >
                                        {x.title}
                                    </span>
                                </button>
                            ))}
                            {adding.map((p) => (
                                <div key={p.key} className="text-muted-foreground flex min-h-12 items-center gap-3 px-4 py-2.5">
                                    <span className="border-primary size-6 shrink-0 rounded-full border-2 opacity-50" aria-hidden />
                                    <span className="min-w-0 flex-1 truncate text-base">{p.title}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    {canEdit && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                add();
                            }}
                            className="bg-surface-card flex min-h-12 items-center gap-3 rounded-full border px-4"
                        >
                            <Plus className="text-muted-foreground size-5 shrink-0" />
                            <input
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                maxLength={255}
                                placeholder={t('calendar.add_day_todo', { date: short })}
                                aria-label={t('calendar.add_day_todo', { date: short })}
                                className="text-primary placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent py-3 text-base outline-none"
                            />
                        </form>
                    )}
                </section>
            </div>
        </ResponsiveModal>
    );
}
