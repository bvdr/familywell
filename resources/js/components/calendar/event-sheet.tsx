import { router } from '@inertiajs/react';
import { Check, ChevronRight, Clock, Contact, FolderKanban, Plus, Repeat, Sun } from 'lucide-react';
import { useRef, useState } from 'react';

import { IconPicker } from '@/components/icon-picker';
import { MemberAvatar } from '@/components/member-avatar';
import { Picker } from '@/components/picker';
import { SettingsSwitch } from '@/components/settings-ui';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { personKey, type CalPerson } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { LIST_COLOR_KEYS, listColor } from '@/lib/tasks';

export type EventForm = {
    id?: number;
    title: string;
    notes: string | null;
    icon: string | null;
    color: string | null;
    start_date: string;
    end_date: string;
    start_time: string | null;
    end_time: string | null;
    repeat: string;
    repeat_interval: number;
    repeat_until: string | null;
    people: CalPerson[];
    project: { id: number } | null;
};

type Named = { id: number; name: string };
const REPEATS = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

// A new event starts at the next half hour and lasts an hour (design C4's smart defaults); late at night it ends at
// 23:59 rather than spilling into tomorrow.
function nextHalfHour(): [string, string] {
    const d = new Date();
    const mins = d.getHours() * 60 + d.getMinutes();
    const start = Math.min(23 * 60 + 30, Math.ceil((mins + 1) / 30) * 30);
    const end = Math.min(23 * 60 + 59, start + 60);
    const hm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    return [hm(start), hm(end)];
}

const blank = (day: string): EventForm => ({
    title: '',
    notes: null,
    icon: null,
    color: null,
    start_date: day,
    end_date: day,
    start_time: nextHalfHour()[0],
    end_time: nextHalfHour()[1],
    repeat: 'none',
    repeat_interval: 1,
    repeat_until: null,
    people: [],
    project: null,
});

// Create / edit an event (design j6QDy4 C4). Keyboard-safe ResponsiveModal (pinned header + the button pinned at
// the bottom). Title first with its icon + colour beside it; time in one group with smart defaults; who's going as
// faces (family) and chips (contacts); project and notes behind "+" chips.
export function EventSheet({
    open,
    onOpenChange,
    event,
    defaultDate,
    members,
    contacts,
    projects,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    event?: EventForm | null;
    defaultDate: string;
    members: Named[];
    contacts: Named[];
    projects: Named[] | null;
}) {
    const t = useT();
    const [f, setF] = useState<EventForm>(event ?? blank(defaultDate));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    // Optional bits (C4): below the fold, each behind a "+" chip until it has something.
    const [more, setMore] = useState({ project: false, notes: false });

    // Reset the form each time the sheet OPENS (React's "adjust state on prop change" pattern, no effect).
    // Keyed on the open transition only, not on `event`: the detail page passes a fresh object every render,
    // and a dependency on it would wipe what the user is typing whenever the parent re-renders.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setF(event ?? blank(defaultDate));
            setErrors({});
            setMore({ project: false, notes: false });
        }
    }

    const set = (patch: Partial<EventForm>) => setF((x) => ({ ...x, ...patch }));
    const allDay = f.start_time === null;
    const has = (p: CalPerson) => f.people.some((q) => personKey(q) === personKey(p));
    const togglePerson = (p: CalPerson) => set({ people: has(p) ? f.people.filter((q) => personKey(q) !== personKey(p)) : [...f.people, p] });

    const save = () => {
        const body = {
            title: f.title,
            notes: f.notes,
            icon: f.icon,
            color: f.color,
            start_date: f.start_date,
            // Moving the start past the end drags the end along instead of failing validation.
            end_date: f.end_date < f.start_date ? f.start_date : f.end_date,
            start_time: f.start_time,
            end_time: f.end_time,
            repeat: f.repeat,
            repeat_interval: f.repeat_interval,
            repeat_until: f.repeat === 'none' ? null : f.repeat_until,
            people: f.people.map(({ type, id }) => ({ type, id })),
            project_id: f.project?.id ?? null,
        };
        // preserveState: the server redirects back to the same page; without it the index remounts and
        // openNewOnArrival() would re-read ?new=1 and pop the sheet open again.
        const opts = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => onOpenChange(false),
        };
        if (f.id) router.patch(`/m/calendar/events/${f.id}`, body, opts);
        else router.post('/m/calendar/events', body, opts);
    };

    const err = (k: string) => errors[k] && <p className="text-destructive text-sm">{errors[k]}</p>;
    const users = members.map((m) => ({ type: 'user' as const, id: m.id, name: m.name }));
    const others = contacts.map((c) => ({ type: 'person' as const, id: c.id, name: c.name }));
    const showProject = !!projects && (more.project || !!f.project);
    const showNotes = more.notes || !!f.notes;
    const row = 'flex min-h-12 items-center gap-3.5 border-b px-4 py-2.5 last:border-b-0';

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={f.id ? t('calendar.edit') : t('calendar.new')}
            footer={
                <button
                    type="button"
                    disabled={saving || !f.title.trim()}
                    onClick={save}
                    className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                >
                    {f.id ? t('calendar.save') : t('calendar.add_event')}
                </button>
            }
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-3">
                        {/* Title first, its icon + colour right beside it. */}
                        <IconPicker
                            value={f.icon ?? 'calendar'}
                            onChange={(icon) => set({ icon })}
                            ariaLabel={t('calendar.icon_color')}
                            className={`size-14 rounded-2xl ${f.color ? listColor(f.color) : 'bg-tile text-tile-foreground'}`}
                            iconClassName="size-6"
                        />
                        <input
                            value={f.title}
                            onChange={(e) => set({ title: e.target.value })}
                            placeholder={t('calendar.title_placeholder')}
                            aria-label={t('calendar.title')}
                            className="bg-surface text-primary placeholder:text-muted-foreground min-w-0 flex-1 rounded-2xl px-4 py-3.5 text-lg font-bold outline-none"
                        />
                    </div>
                    {err('title')}
                    <div className="flex gap-2 pt-1 pl-[4.25rem]">
                        {LIST_COLOR_KEYS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                aria-label={t('tasks.color_aria', { color: c })}
                                aria-pressed={f.color === c}
                                onClick={() => set({ color: f.color === c ? null : c })}
                                className={`flex size-7 items-center justify-center rounded-full ${listColor(c)} ${f.color === c ? 'ring-primary ring-2 ring-offset-1' : ''}`}
                            >
                                {f.color === c && <Check className="size-3.5" />}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="bg-surface-card overflow-hidden rounded-[22px] border">
                    <div className={row}>
                        <Sun className="text-primary size-5 shrink-0" />
                        <span className="text-primary flex-1 text-[17px]">{t('calendar.all_day')}</span>
                        <SettingsSwitch
                            on={allDay}
                            label={t('calendar.all_day')}
                            onToggle={() =>
                                set(allDay ? (([a, b]) => ({ start_time: a, end_time: b }))(nextHalfHour()) : { start_time: null, end_time: null })
                            }
                        />
                    </div>
                    {(['start', 'end'] as const).map((k) => (
                        <div key={k} className={row}>
                            <Clock className="text-primary size-5 shrink-0" />
                            <span className="text-primary shrink-0 text-[17px]">{t(k === 'start' ? 'calendar.starts' : 'calendar.ends')}</span>
                            <span className="flex min-w-0 flex-1 items-center justify-end gap-1">
                                <NativePick
                                    type="date"
                                    label={t(k === 'start' ? 'calendar.starts' : 'calendar.ends')}
                                    value={f[`${k}_date`]}
                                    text={new Date(`${f[`${k}_date`]}T12:00`).toLocaleDateString(appLocale(), {
                                        weekday: 'short',
                                        month: 'short',
                                        day: 'numeric',
                                    })}
                                    onChange={(v) => v && set({ [`${k}_date`]: v } as Partial<EventForm>)}
                                />
                                {!allDay && (
                                    <>
                                        <span className="text-muted-foreground">·</span>
                                        <NativePick
                                            type="time"
                                            label={t(k === 'start' ? 'calendar.starts' : 'calendar.ends')}
                                            value={f[`${k}_time`] ?? ''}
                                            text={(f[`${k}_time`] ?? '').replace(/^0(\d)/, '$1')}
                                            onChange={(v) => set({ [`${k}_time`]: v || null } as Partial<EventForm>)}
                                        />
                                    </>
                                )}
                            </span>
                        </div>
                    ))}
                    <label className={`${row} relative`}>
                        <Repeat className="text-primary size-5 shrink-0" />
                        <span className="text-primary flex-1 text-[17px]">{t('calendar.repeat')}</span>
                        <span className="text-primary font-semibold">{t(`calendar.repeat_${f.repeat}`)}</span>
                        <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                        {/* The phone's own picker opens on a tap. */}
                        <select
                            value={f.repeat}
                            onChange={(e) => set({ repeat: e.target.value })}
                            aria-label={t('calendar.repeat')}
                            className="absolute inset-0 size-full cursor-pointer opacity-0"
                        >
                            {REPEATS.map((r) => (
                                <option key={r} value={r}>
                                    {t(`calendar.repeat_${r}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {f.repeat !== 'none' && (
                        <div className={`${row} text-primary text-base`}>
                            <span>{t('calendar.every')}</span>
                            <input
                                type="number"
                                min={1}
                                max={99}
                                inputMode="numeric"
                                aria-label={t('calendar.every')}
                                className="bg-surface w-14 rounded-lg px-2 py-1 text-center text-base font-semibold outline-none"
                                value={f.repeat_interval}
                                onChange={(e) => set({ repeat_interval: Math.min(99, Math.max(1, Number(e.target.value) || 1)) })}
                            />
                            <span className="ml-auto">{t('calendar.until')}</span>
                            <NativePick
                                type="date"
                                label={t('calendar.until')}
                                value={f.repeat_until ?? ''}
                                text={
                                    f.repeat_until
                                        ? new Date(`${f.repeat_until}T12:00`).toLocaleDateString(appLocale(), {
                                              month: 'short',
                                              day: 'numeric',
                                              year: 'numeric',
                                          })
                                        : t('calendar.until_forever')
                                }
                                onChange={(v) => set({ repeat_until: v || null })}
                            />
                        </div>
                    )}
                </div>
                {err('start_date')}
                {err('end_date')}
                {err('start_time')}
                {err('end_time')}
                {err('repeat_until')}

                {users.length + others.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-primary text-sm font-semibold">{t('calendar.whos_going')}</h3>
                        <div className="flex flex-wrap items-center gap-2">
                            {users.map((p) => (
                                <button
                                    key={personKey(p)}
                                    type="button"
                                    aria-pressed={has(p)}
                                    aria-label={p.name}
                                    onClick={() => togglePerson(p)}
                                    className={`rounded-full ${has(p) ? 'ring-primary ring-offset-background ring-2 ring-offset-2' : 'opacity-60'}`}
                                >
                                    <MemberAvatar userId={p.id} name={p.name} className="size-10 text-sm" />
                                </button>
                            ))}
                        </div>
                        {/* Contacts (a family can have hundreds): picked ones in a searchable list, not a wall of chips. */}
                        {others.length > 0 && (
                            <Picker
                                multi
                                ariaLabel={t('calendar.contacts')}
                                placeholder={t('calendar.add_contact')}
                                fallbackIcon={Contact}
                                options={others.map((p) => ({ value: personKey(p), label: p.name }))}
                                selected={f.people.filter((p) => p.type === 'person').map(personKey)}
                                onChange={(keys) =>
                                    set({
                                        people: [...f.people.filter((p) => p.type === 'user'), ...others.filter((p) => keys.includes(personKey(p)))],
                                    })
                                }
                            />
                        )}
                        {err('people')}
                    </section>
                )}

                {showProject && projects && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-primary text-sm font-semibold">{t('calendar.project')}</h3>
                        <Picker
                            ariaLabel={t('calendar.project')}
                            fallbackIcon={FolderKanban}
                            options={[
                                { value: '', label: t('calendar.no_project') },
                                ...projects.map((p) => ({ value: String(p.id), label: p.name })),
                            ]}
                            selected={[f.project ? String(f.project.id) : '']}
                            onChange={(next) => set({ project: next[0] ? { id: Number(next[0]) } : null })}
                        />
                    </section>
                )}
                {showNotes && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-primary text-sm font-semibold">{t('calendar.notes')}</h3>
                        <textarea
                            rows={3}
                            autoFocus={more.notes && !f.notes}
                            value={f.notes ?? ''}
                            onChange={(e) => set({ notes: e.target.value || null })}
                            className="bg-surface text-primary rounded-2xl px-4 py-3 text-base outline-none"
                        />
                    </section>
                )}
                {(!showProject || !showNotes) && (
                    <div className="flex flex-wrap gap-2">
                        {projects && !showProject && (
                            <button
                                type="button"
                                onClick={() => setMore({ ...more, project: true })}
                                className="border-border text-primary flex items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-semibold"
                            >
                                <Plus className="size-4" /> {t('calendar.project')}
                            </button>
                        )}
                        {!showNotes && (
                            <button
                                type="button"
                                onClick={() => setMore({ ...more, notes: true })}
                                className="border-border text-primary flex items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-semibold"
                            >
                                <Plus className="size-4" /> {t('calendar.notes')}
                            </button>
                        )}
                    </div>
                )}
            </div>
        </ResponsiveModal>
    );
}

// A date or time drawn as plain words ("Thu, Oct 22", "3:00"), the phone's own picker on a tap: the input sits
// under the words, not display:none, because showPicker() needs a rendered input.
function NativePick({
    type,
    label,
    value,
    text,
    onChange,
}: {
    type: 'date' | 'time';
    label: string;
    value: string;
    text: string;
    onChange: (v: string) => void;
}) {
    const ref = useRef<HTMLInputElement>(null);
    return (
        <span className="relative">
            <button
                type="button"
                aria-label={label}
                onClick={() => (ref.current?.showPicker ? ref.current.showPicker() : ref.current?.focus())}
                className="text-primary rounded-lg px-1 py-1 text-base font-semibold whitespace-nowrap"
            >
                {text || '—'}
            </button>
            <input
                ref={ref}
                type={type}
                value={value}
                tabIndex={-1}
                aria-hidden
                onChange={(e) => onChange(e.target.value)}
                className="pointer-events-none absolute inset-0 -z-10 opacity-0"
            />
        </span>
    );
}
