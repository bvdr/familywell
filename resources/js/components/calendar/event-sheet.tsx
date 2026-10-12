import { router } from '@inertiajs/react';
import { Check, FolderKanban, Repeat } from 'lucide-react';
import { useState } from 'react';

import { IconPicker } from '@/components/icon-picker';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Textarea } from '@/components/ui/textarea';
import { personKey, type CalPerson } from '@/lib/calendar';
import { useT } from '@/lib/i18n';
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

// Same label style as the tasks/projects create sheets.
const LABEL = 'text-muted-foreground text-xs font-semibold tracking-widest uppercase';

const blank = (day: string): EventForm => ({
    title: '',
    notes: null,
    icon: null,
    color: null,
    start_date: day,
    end_date: day,
    start_time: null,
    end_time: null,
    repeat: 'none',
    repeat_interval: 1,
    repeat_until: null,
    people: [],
    project: null,
});

// Create / edit an event. Keyboard-safe ResponsiveModal (pinned header + Save footer). People are toggle chips
// (members, then contacts) rather than a search box: families have a handful of people.
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

    // Reset the form each time the sheet OPENS (React's "adjust state on prop change" pattern, no effect).
    // Keyed on the open transition only, not on `event`: the detail page passes a fresh object every render,
    // and a dependency on it would wipe what the user is typing whenever the parent re-renders.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setF(event ?? blank(defaultDate));
            setErrors({});
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

    const chip = (on: boolean) =>
        `shrink-0 rounded-full border px-3 py-1.5 text-sm transition-colors ${on ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'}`;
    const err = (k: string) => errors[k] && <p className="text-destructive text-sm">{errors[k]}</p>;
    const people: CalPerson[] = [
        ...members.map((m) => ({ type: 'user' as const, id: m.id, name: m.name })),
        ...contacts.map((c) => ({ type: 'person' as const, id: c.id, name: c.name })),
    ];

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={f.id ? t('calendar.edit') : t('calendar.new')}
            footer={
                <Button className="w-full" disabled={saving || !f.title.trim()} onClick={save}>
                    {t('calendar.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="flex flex-col gap-1">
                    <div className="flex items-center gap-3">
                        {/* Tile shows the chosen colour behind the icon, like a task list's tile. */}
                        <IconPicker
                            value={f.icon ?? 'calendar'}
                            onChange={(icon) => set({ icon })}
                            ariaLabel={t('calendar.icon_color')}
                            className={`size-12 rounded-xl ${f.color ? listColor(f.color) : 'bg-tile text-tile-foreground'}`}
                            iconClassName="size-6"
                        />
                        <Input
                            autoFocus
                            value={f.title}
                            onChange={(e) => set({ title: e.target.value })}
                            placeholder={t('calendar.title_placeholder')}
                            className="text-base"
                        />
                    </div>
                    {err('title')}
                </div>

                <div className="flex flex-wrap gap-2">
                    {LIST_COLOR_KEYS.map((c) => (
                        <button
                            key={c}
                            type="button"
                            aria-label={c}
                            onClick={() => set({ color: f.color === c ? null : c })}
                            className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${f.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                        >
                            {f.color === c && <Check className="size-4" />}
                        </button>
                    ))}
                </div>

                <label className="flex items-center justify-between gap-3 text-base">
                    {t('calendar.all_day')}
                    <Checkbox
                        checked={allDay}
                        onCheckedChange={(on) => set(on ? { start_time: null, end_time: null } : { start_time: '09:00', end_time: '10:00' })}
                    />
                </label>

                {/* Stacked full-width rows: native date inputs won't shrink in a narrow grid cell (iOS). */}
                {(['start', 'end'] as const).map((k) => (
                    <div key={k} className="flex flex-col gap-1.5">
                        <span className={LABEL}>{t(k === 'start' ? 'calendar.starts' : 'calendar.ends')}</span>
                        <div className="flex gap-2">
                            <Input
                                type="date"
                                className="flex-1 text-base"
                                value={f[`${k}_date`]}
                                onChange={(e) => set({ [`${k}_date`]: e.target.value } as Partial<EventForm>)}
                            />
                            {!allDay && (
                                <Input
                                    type="time"
                                    className="w-32 text-base"
                                    value={f[`${k}_time`] ?? ''}
                                    onChange={(e) => set({ [`${k}_time`]: e.target.value || null } as Partial<EventForm>)}
                                />
                            )}
                        </div>
                        {err(`${k}_date`)}
                        {err(`${k}_time`)}
                    </div>
                ))}

                <div className="flex flex-col gap-1.5">
                    <span className={LABEL}>{t('calendar.repeat')}</span>
                    <Picker
                        ariaLabel={t('calendar.repeat')}
                        fallbackIcon={Repeat}
                        options={REPEATS.map((r) => ({ value: r, label: t(`calendar.repeat_${r}`) }))}
                        selected={[f.repeat]}
                        onChange={(next) => next[0] && set({ repeat: next[0] })}
                    />
                    {f.repeat !== 'none' && (
                        <div className="flex items-center gap-2">
                            <span className="text-sm">{t('calendar.every')}</span>
                            <Input
                                type="number"
                                min={1}
                                max={99}
                                className="w-16 text-base"
                                value={f.repeat_interval}
                                onChange={(e) => set({ repeat_interval: Math.min(99, Math.max(1, Number(e.target.value) || 1)) })}
                            />
                            <span className="text-sm">{t('calendar.until')}</span>
                            <Input
                                type="date"
                                className="flex-1 text-base"
                                value={f.repeat_until ?? ''}
                                aria-label={t('calendar.until')}
                                onChange={(e) => set({ repeat_until: e.target.value || null })}
                            />
                        </div>
                    )}
                    {err('repeat_until')}
                </div>

                {people.length > 0 && (
                    <div className="flex flex-col gap-1.5">
                        <span className={LABEL}>{t('calendar.people')}</span>
                        <div className="flex flex-wrap gap-2">
                            {people.map((p) => (
                                <button
                                    key={personKey(p)}
                                    type="button"
                                    aria-pressed={has(p)}
                                    className={chip(has(p))}
                                    onClick={() => togglePerson(p)}
                                >
                                    {p.name}
                                </button>
                            ))}
                        </div>
                        {err('people')}
                    </div>
                )}

                {projects && (
                    <div className="flex flex-col gap-1.5">
                        <span className={LABEL}>{t('calendar.project')}</span>
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
                    </div>
                )}

                <div className="flex flex-col gap-1.5">
                    <span className={LABEL}>{t('calendar.notes')}</span>
                    <Textarea rows={3} value={f.notes ?? ''} onChange={(e) => set({ notes: e.target.value || null })} />
                </div>
            </div>
        </ResponsiveModal>
    );
}
