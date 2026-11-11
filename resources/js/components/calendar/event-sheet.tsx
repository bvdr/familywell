import { router } from '@inertiajs/react';
import { Check, ChevronRight, Clock, Contact, FolderKanban, Plus, Repeat, Sun } from 'lucide-react';
import { useState } from 'react';

import { DatePick } from '@/components/add-details';
import { stripe } from '@/components/calendar/agenda';
import { DayIcon, EVENT_ICONS, ICON_GROUPS, IconGrid, iconsFor, isDrawnIcon } from '@/components/day-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { Picker } from '@/components/picker';
import { SettingsSwitch } from '@/components/settings-ui';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { CATEGORY_COLORS, personKey, type CalCategory, type CalPerson } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { guessIcons } from '@/lib/icon-guess';
import { listColor } from '@/lib/tasks';

export type EventForm = {
    id?: number;
    title: string;
    notes: string | null;
    icon: string | null;
    color: string | null;
    category: string | null;
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
// Sheet screens: the form (C12), the icon picker (C13) and a new category (C14), all inside the one sheet.
type View = 'form' | 'icons' | 'category';

// The icon an event shows: the one picked, else a guess from its title, else its category's, else a plain calendar.
// PHP twin: the `$icon` line in CalendarEvent::itemsFor (the agenda and Home get the same answer from the server).
// icon === null means "never picked", so the guess keeps following the title.
export function eventIcon(e: { icon: string | null; title: string; category: string | null }, categories: CalCategory[]): string {
    return e.icon || guessIcons(e.title).icons[0] || categories.find((c) => c.slug === e.category)?.icon || 'calendar';
}
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
    category: null,
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

// Create / edit an event (design j6QDy4 C4, C12-C14). Keyboard-safe ResponsiveModal (pinned header + the button pinned
// at the bottom). Title first with its icon tile beside it (the tile takes the category's colour) and the category chips
// right under it; time in one group with smart defaults; who's going as faces (family) and chips (contacts); project and
// notes behind "+" chips. The tile opens the icon picker and "+ New category" the category form, both in this same sheet.
export function EventSheet({
    open,
    onOpenChange,
    event,
    defaultDate,
    members,
    contacts,
    projects,
    categories,
    pickDate,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    event?: EventForm | null;
    defaultDate: string;
    members: Named[];
    contacts: Named[];
    projects: Named[] | null;
    categories: CalCategory[];
    pickDate?: boolean; // open with the start date's calendar showing (the event page's date line was tapped)
}) {
    const t = useT();
    const [f, setF] = useState<EventForm>(event ?? blank(defaultDate));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [view, setView] = useState<View>('form');
    const [moreIcons, setMoreIcons] = useState(false);
    // C14's draft. icon null = not picked, so it follows the name's guess like an event's does.
    const [cat, setCat] = useState<{ name: string; icon: string | null; color: string }>({ name: '', icon: null, color: CATEGORY_COLORS[0] });
    const [catErrors, setCatErrors] = useState<Record<string, string>>({});
    // Which date has its month calendar open under its row (the tasks' DatePick). The hidden native date input that
    // was here relied on showPicker(), which does nothing on iPhone: tapping a date did nothing (2026-11-05).
    const [picking, setPicking] = useState<'start_date' | 'end_date' | 'repeat_until' | null>(null);
    const dateText = (d: string, o: Intl.DateTimeFormatOptions) => new Date(`${d}T12:00`).toLocaleDateString(appLocale(), o);
    const dateButton = (k: 'start_date' | 'end_date' | 'repeat_until', label: string, text: string) => (
        <button
            type="button"
            aria-label={label}
            aria-expanded={picking === k}
            onClick={() => setPicking(picking === k ? null : k)}
            className={`rounded-lg px-1 py-1 text-base font-semibold whitespace-nowrap ${picking === k ? 'bg-primary text-primary-foreground px-2' : 'text-primary'}`}
        >
            {text}
        </button>
    );
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
            setView('form');
            setMoreIcons(false);
            setPicking(pickDate ? 'start_date' : null);
            setMore({ project: false, notes: false });
        }
    }

    const set = (patch: Partial<EventForm>) => setF((x) => ({ ...x, ...patch }));
    const allDay = f.start_time === null;
    const has = (p: CalPerson) => f.people.some((q) => personKey(q) === personKey(p));
    const togglePerson = (p: CalPerson) => set({ people: has(p) ? f.people.filter((q) => personKey(q) !== personKey(p)) : [...f.people, p] });

    // A category puts its colour on the event (the tile and bars take it); tapping it again clears it, and the
    // colour with it unless that was changed since.
    const pickCategory = (c: CalCategory) =>
        set(f.category === c.slug ? { category: null, color: f.color === c.color ? null : f.color } : { category: c.slug, color: c.color });

    const openNewCategory = () => {
        // Start on the next swatch: a family's custom categories cycle through them, so neighbours differ.
        setCat({ name: '', icon: null, color: CATEGORY_COLORS[categories.filter((c) => !c.builtin).length % CATEGORY_COLORS.length] });
        setCatErrors({});
        setView('category');
    };
    const saveCategory = () => {
        const name = cat.name.trim();
        const icon = cat.icon ?? guessIcons(name).icons[0] ?? 'tag';
        router.post(
            '/m/calendar/categories',
            { name, icon, color: cat.color },
            {
                preserveScroll: true,
                preserveState: true, // the half-filled event form must survive the round trip
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (e) => setCatErrors(e),
                onSuccess: (page) => {
                    // The server picked the slug; find the new category by its name in the refreshed list and land on it.
                    const made = (page.props.categories as CalCategory[]).find((c) => !c.builtin && c.name === name);
                    if (made) set({ category: made.slug, color: made.color });
                    setView('form');
                },
            },
        );
    };

    const save = () => {
        const body = {
            title: f.title,
            notes: f.notes,
            icon: f.icon,
            color: f.color,
            category: f.category,
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

    const back = { onBack: () => setView('form'), backLabel: t(f.id ? 'calendar.edit' : 'calendar.new') };
    const heading = 'text-muted-foreground font-mono text-xs font-semibold tracking-widest uppercase';

    // C13: the icon picker, inside the same sheet. The first row is guessed from the title; tapping an icon goes
    // straight back. "More icons" is the Days picker's full set (minus the two it draws itself, which the rest of
    // the calendar couldn't show).
    if (view === 'icons') {
        const guess = guessIcons(f.title);
        const current = eventIcon(f, categories);
        const pick = (icon: string | null) => {
            set({ icon });
            setView('form');
        };
        const drawable = (names: readonly string[]) => names.filter((n) => !isDrawnIcon(n));
        return (
            <ResponsiveModal
                open={open}
                onOpenChange={onOpenChange}
                title={t('days.pick_icon')}
                {...back}
                viewKey="icons"
                aside={
                    <button type="button" onClick={() => setMoreIcons(!moreIcons)} className="text-ink-2 text-sm font-semibold">
                        {t(moreIcons ? 'days.fewer_icons' : 'days.more_icons')}
                    </button>
                }
            >
                <div className="flex flex-col gap-5">
                    {guess.word && (
                        <section className="flex flex-col gap-2">
                            <h3 className={heading}>{t('calendar.icon_suggested', { word: guess.word })}</h3>
                            <IconGrid icons={guess.icons} value={current} onPick={pick} />
                        </section>
                    )}
                    {moreIcons ? (
                        iconsFor('celebrate', true).map((g) => (
                            <section key={g} className="flex flex-col gap-2">
                                <h3 className={heading}>{t(`days.icons_${g}`)}</h3>
                                <IconGrid icons={drawable(ICON_GROUPS[g])} value={current} onPick={pick} />
                            </section>
                        ))
                    ) : (
                        <section className="flex flex-col gap-2">
                            <h3 className={heading}>{t('days.icons_everyday')}</h3>
                            <IconGrid icons={EVENT_ICONS} value={current} onPick={pick} />
                        </section>
                    )}
                    {/* Back to "never picked", so the icon follows the title again. */}
                    {f.icon && (
                        <button type="button" onClick={() => pick(null)} className="text-ink-2 self-start text-sm font-semibold">
                            {t('calendar.icon_auto')}
                        </button>
                    )}
                </div>
            </ResponsiveModal>
        );
    }

    // C14: a new category: name, icon, colour, done. It lands selected on the event being edited.
    if (view === 'category') {
        const name = cat.name.trim();
        const icon = cat.icon ?? guessIcons(name).icons[0] ?? 'tag';
        const choices = [...new Set([...guessIcons(name).icons, ...EVENT_ICONS])];
        return (
            <ResponsiveModal
                open={open}
                onOpenChange={onOpenChange}
                title={t('calendar.new_category')}
                {...back}
                viewKey="category"
                footer={
                    <button
                        type="button"
                        disabled={saving || !name}
                        onClick={saveCategory}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('calendar.save_category')}
                    </button>
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-3">
                            <span className={`flex size-13 shrink-0 items-center justify-center rounded-[14px] ${listColor(cat.color)}`}>
                                <DayIcon name={icon} className="size-6" />
                            </span>
                            <input
                                value={cat.name}
                                onChange={(e) => setCat({ ...cat, name: e.target.value })}
                                placeholder={t('calendar.category_name_placeholder')}
                                aria-label={t('calendar.category_name')}
                                maxLength={40}
                                className="bg-surface text-primary placeholder:text-muted-foreground min-w-0 flex-1 rounded-2xl px-4 py-3.5 text-lg font-bold outline-none"
                            />
                        </div>
                        {catErrors.name && <p className="text-destructive text-sm">{catErrors.name}</p>}
                    </div>
                    <section className="flex flex-col gap-2">
                        <h3 className={heading}>{t('days.icon')}</h3>
                        <IconGrid icons={choices} value={icon} onPick={(next) => setCat({ ...cat, icon: next })} />
                    </section>
                    <section className="flex flex-col gap-2">
                        <h3 className={heading}>{t('calendar.category_color')}</h3>
                        <div className="flex flex-wrap gap-2.5">
                            {CATEGORY_COLORS.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    aria-label={t('tasks.color_aria', { color: c })}
                                    aria-pressed={cat.color === c}
                                    onClick={() => setCat({ ...cat, color: c })}
                                    className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${cat.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                                >
                                    {cat.color === c && <Check className="size-4" />}
                                </button>
                            ))}
                        </div>
                    </section>
                    <p className="text-muted-foreground text-sm">{t('calendar.category_hint', { name: name || t('calendar.category') })}</p>
                </div>
            </ResponsiveModal>
        );
    }

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            viewKey="form"
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
                        {/* The tile (C12): 52px, the icon on the category's colour. Tap it for the picker (C13). */}
                        <button
                            type="button"
                            onClick={() => setView('icons')}
                            aria-label={t('calendar.icon_color')}
                            className={`flex size-13 shrink-0 items-center justify-center rounded-[14px] ${f.color ? listColor(f.color) : 'bg-tile text-tile-foreground'}`}
                        >
                            <DayIcon name={eventIcon(f, categories)} className="size-6" />
                        </button>
                        <input
                            value={f.title}
                            onChange={(e) => set({ title: e.target.value })}
                            placeholder={t('calendar.title_placeholder')}
                            aria-label={t('calendar.title')}
                            className="bg-surface text-primary placeholder:text-muted-foreground min-w-0 flex-1 rounded-2xl px-4 py-3.5 text-lg font-bold outline-none"
                        />
                    </div>
                    {err('title')}
                    {/* Category (the calendar's filter chips, the family's own included), right under the title. Tap
                        again to clear. */}
                    <div
                        role="group"
                        aria-label={t('calendar.category')}
                        className="-mx-1 flex gap-2 overflow-x-auto px-1 pt-2 [scrollbar-width:none]"
                        data-scroll-x
                    >
                        {categories.map((c) => {
                            const on = f.category === c.slug;
                            return (
                                <button
                                    key={c.slug}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => pickCategory(c)}
                                    className={`flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm ${on ? 'border-primary bg-accent-subtle text-primary font-semibold' : 'border-border text-muted-foreground'}`}
                                >
                                    <span className={`size-[7px] rounded-full ${stripe(c.color)}`} />
                                    {c.name}
                                </button>
                            );
                        })}
                        <button
                            type="button"
                            onClick={openNewCategory}
                            className="border-border text-primary flex shrink-0 items-center gap-1.5 rounded-full border border-dashed px-3 py-1.5 text-sm font-semibold"
                        >
                            <Plus className="size-3.5" />
                            {t('calendar.new_category')}
                        </button>
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
                        <div key={k}>
                            <div className={row}>
                                <Clock className="text-primary size-5 shrink-0" />
                                <span className="text-primary shrink-0 text-[17px]">{t(k === 'start' ? 'calendar.starts' : 'calendar.ends')}</span>
                                <span className="flex min-w-0 flex-1 items-center justify-end gap-1">
                                    {dateButton(
                                        `${k}_date`,
                                        t(k === 'start' ? 'calendar.starts' : 'calendar.ends'),
                                        dateText(f[`${k}_date`], { weekday: 'short', month: 'short', day: 'numeric' }),
                                    )}
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
                            {picking === `${k}_date` && (
                                <div className="border-b px-4 py-3">
                                    <DatePick
                                        value={f[`${k}_date`]}
                                        required
                                        onChange={(v) => {
                                            // A start moved past the end drags the end along (an event can't end before it starts).
                                            if (k === 'start') set({ start_date: v, ...(f.end_date < v ? { end_date: v } : {}) });
                                            else set({ end_date: v });
                                            setPicking(null);
                                        }}
                                    />
                                </div>
                            )}
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
                            {dateButton(
                                'repeat_until',
                                t('calendar.until'),
                                f.repeat_until
                                    ? dateText(f.repeat_until, { month: 'short', day: 'numeric', year: 'numeric' })
                                    : t('calendar.until_forever'),
                            )}
                        </div>
                    )}
                    {f.repeat !== 'none' && picking === 'repeat_until' && (
                        <div className="border-t px-4 py-3">
                            {/* Clear date = repeat forever. */}
                            <DatePick
                                value={f.repeat_until ?? ''}
                                onChange={(v) => {
                                    set({ repeat_until: v || null });
                                    setPicking(null);
                                }}
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
    type: 'time';
    label: string;
    value: string;
    text: string;
    onChange: (v: string) => void;
}) {
    // The transparent native input lies ON the text, so the tap lands on it: iPhone opens its own picker only for a
    // real tap on the input; showPicker() from a button under it did nothing there (2026-11-05).
    return (
        <span className="text-primary relative rounded-lg px-1 py-1 text-base font-semibold whitespace-nowrap">
            {text || '—'}
            <input
                type={type}
                value={value}
                aria-label={label}
                onChange={(e) => onChange(e.target.value)}
                className="absolute inset-0 size-full cursor-pointer opacity-0"
            />
        </span>
    );
}
