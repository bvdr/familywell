import { Head, router } from '@inertiajs/react';
import { Bell, BellOff, ChevronDown, Heart, Lock, Minus, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

import { DayIcon, ICON_GROUPS, IconGrid, iconsFor } from '@/components/day-icon';
import { Picker } from '@/components/picker';
import { Segmented } from '@/components/segmented';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { localToday } from '@/lib/date-groups';
import { useLocale, useT } from '@/lib/i18n';
import { save as saveInBackground } from '@/lib/save';

// Days to remember (design board 10c, A1-A3c). A day as DaysController / Day::toPayload sends it. remindDays: null =
// no reminder (they start off), 0 = on the day. years = the count on the next date (null without a year).
export type Day = {
    id: number;
    title: string;
    tone: 'celebrate' | 'quiet';
    icon: string;
    month: number;
    day: number;
    year: number | null;
    note: string | null;
    personIds: number[];
    remindDays: number | null;
    next: string;
    daysUntil: number;
    years: number | null;
    visibility: string;
};
type Person = { id: number; name: string };
type T = ReturnType<typeof useT>;

// The reminder chips (A2); anything else is Custom (1-60 stepper). null = Off.
const PRESETS = [0, 1, 3, 7, 14];
// Reminders arrive at 9:00 family time (NotifyDays::SEND_HOUR).
const SEND_HOUR = 9;
// The icon a new day starts with, per tone (A2: heart, A3: flower), until one is picked.
const TONE_ICON = { celebrate: 'heart', quiet: 'flower-2' } as const;

export function remindLabel(t: T, n: number): string {
    if (n === 0) return t('days.on_day');
    if (n % 7 === 0) return t('days.n_weeks', { count: n / 7 });
    return t('days.n_days', { count: n });
}

// The second line of a row: "12 years · in 18 days" for Celebrate, "Since 2019" for Quiet (no countdown).
export function daySub(t: T, d: Day): string {
    if (d.tone === 'quiet') return d.year ? t('days.since', { year: d.year }) : t('days.every_year');
    const when = d.daysUntil === 0 ? t('days.today_word') : d.daysUntil === 1 ? t('days.tomorrow') : t('days.in_n_days', { count: d.daysUntil });
    return d.years ? `${t('days.n_years', { count: d.years })} · ${when}` : when;
}

function monthNames(locale: string): string[] {
    const fmt = new Intl.DateTimeFormat(locale, { month: 'short' });
    return Array.from({ length: 12 }, (_, i) => fmt.format(new Date(2024, i, 1)));
}

const parseDay = (ymd: string) => new Date(ymd + 'T00:00:00');

// The next date on/after today for a month/day (Feb 29 rolls to Mar 1 off leap years, like Day::next()).
function nextOn(month: number, day: number): Date {
    const today = parseDay(localToday());
    const d = new Date(today.getFullYear(), month - 1, day);
    if (d < today) d.setFullYear(d.getFullYear() + 1, month - 1, day);
    return d;
}

export default function DaysIndex({ days, people }: { days: Day[]; people: Person[] }) {
    const t = useT();
    const locale = useLocale();
    const months = monthNames(locale);
    const [sheet, setSheet] = useState<{ open: boolean; edit: Day | null }>({ open: false, edit: null });
    useAddAction(() => setSheet({ open: true, edit: null }), { label: 'days.add', icon: Heart });

    const removed = useOptimistic<boolean>();
    const visible = days.filter((d) => !removed.get(d.id, false));
    const today = visible.filter((d) => d.daysUntil === 0);
    // Soonest first already, so the months run from this one forward and wrap into next year.
    const groups: [string, Day[]][] = [];
    for (const d of visible.filter((d) => d.daysUntil > 0)) {
        const date = parseDay(d.next);
        const m = new Intl.DateTimeFormat(
            locale,
            date.getFullYear() === new Date().getFullYear() ? { month: 'long' } : { month: 'long', year: 'numeric' },
        ).format(date);
        if (groups.at(-1)?.[0] === m) groups.at(-1)![1].push(d);
        else groups.push([m, [d]]);
    }
    const remove = (d: Day) => {
        removed.set(d.id, true);
        void saveInBackground('delete', route('days.destroy', d.id)).then(() => removed.clear(d.id));
    };
    const open = (edit: Day | null) => setSheet({ open: true, edit });

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.days'), href: '/m/days' }]}>
            <Head title={t('nav.days')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="font-heading text-primary min-w-0 text-4xl font-extrabold tracking-tight break-words hyphens-auto">
                        {t('nav.days')}
                    </h1>
                    <button
                        type="button"
                        onClick={() => open(null)}
                        aria-label={t('days.add')}
                        className="bg-paper-2 text-ink flex size-10 shrink-0 items-center justify-center rounded-full"
                    >
                        <Plus className="size-5" />
                    </button>
                </div>

                {/* Today: Celebrate gets the sun, Quiet a calm card ("Remembering Grandpa Ion · 3 years today"). */}
                {today.map((d) => (
                    <button
                        key={d.id}
                        type="button"
                        onClick={() => open(d)}
                        className={`flex items-center gap-3.5 rounded-[22px] p-4 text-left ${d.tone === 'quiet' ? 'bg-card border' : 'bg-sun text-ink'}`}
                    >
                        <span
                            className={`flex size-13 shrink-0 items-center justify-center rounded-2xl ${d.tone === 'quiet' ? 'bg-paper-2' : 'bg-card'}`}
                        >
                            <DayIcon name={d.icon} className="text-ink-2 size-6" />
                        </span>
                        <span className="min-w-0">
                            <span className="text-muted-foreground block font-mono text-[11px] font-semibold tracking-widest uppercase">
                                {t('days.today_label')}
                            </span>
                            <span className="text-ink block text-xl font-bold break-words">
                                {d.tone === 'quiet' ? t('days.remembering', { title: d.title }) : d.title}
                            </span>
                            {d.years && (
                                <span className="text-muted-foreground block text-sm font-medium">{t('days.years_today', { count: d.years })}</span>
                            )}
                        </span>
                    </button>
                ))}

                {visible.length === 0 && (
                    <div className="flex flex-col items-center gap-3 py-10 text-center">
                        <p className="text-muted-foreground max-w-xs text-sm">{t('days.empty')}</p>
                        <button
                            type="button"
                            onClick={() => open(null)}
                            className="bg-accent-strong text-accent-strong-foreground rounded-full px-5 py-2.5 font-semibold"
                        >
                            {t('days.add')}
                        </button>
                    </div>
                )}

                {groups.map(([month, rows]) => (
                    <section key={month} className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-widest uppercase">{month}</h2>
                        <div className="bg-card overflow-hidden rounded-[22px] border">
                            {rows.map((d) => (
                                <button
                                    key={d.id}
                                    type="button"
                                    onClick={() => open(d)}
                                    className="flex w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0"
                                >
                                    {/* Date tile: sun-soft for Celebrate, paper for Quiet. */}
                                    <span
                                        className={`flex h-12.5 w-11.5 shrink-0 flex-col items-center justify-center rounded-2xl ${d.tone === 'quiet' ? 'bg-paper-2' : 'bg-sun-soft'}`}
                                    >
                                        <span className="text-muted-foreground font-mono text-[10px] font-semibold uppercase">
                                            {months[d.month - 1]}
                                        </span>
                                        <span className="text-ink text-xl leading-none font-bold">{d.day}</span>
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <DayIcon name={d.icon} className="text-ink-2 size-3.5 shrink-0" />
                                            <span className="text-primary truncate text-base font-semibold">{d.title}</span>
                                        </span>
                                        <span className="text-muted-foreground block truncate text-sm">{daySub(t, d)}</span>
                                    </span>
                                    {/* The bell only when a reminder is on (A1). */}
                                    {d.remindDays !== null && (
                                        <span className="text-muted-foreground flex shrink-0 items-center gap-1 text-[13px] font-medium">
                                            <Bell className="size-3.5" />
                                            {remindLabel(t, d.remindDays)}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            <DaySheet
                open={sheet.open}
                edit={sheet.edit}
                people={people}
                months={months}
                onClose={() => setSheet((s) => ({ ...s, open: false }))}
                onRemove={remove}
            />
        </AppLayout>
    );
}

type Draft = {
    title: string;
    tone: 'celebrate' | 'quiet';
    icon: string | null; // null = not picked yet, so it follows the tone
    day: string;
    month: string;
    year: string;
    note: string | null; // null = "+ Note" not opened
    personIds: number[];
    remind: number | null;
    visibility: 'family' | 'private';
};

const blank: Draft = {
    title: '',
    tone: 'celebrate',
    icon: null,
    day: '',
    month: '',
    year: '',
    note: null,
    personIds: [],
    remind: null,
    visibility: 'family',
};

// A2/A3 (the form) and A3b/A3c (the icon picker, inside the same sheet) for add and edit.
function DaySheet({
    open,
    edit,
    people,
    months,
    onClose,
    onRemove,
}: {
    open: boolean;
    edit: Day | null;
    people: Person[];
    months: string[];
    onClose: () => void;
    onRemove: (d: Day) => void;
}) {
    const t = useT();
    const locale = useLocale();
    const [d, setD] = useState<Draft>(blank);
    const [view, setView] = useState<'form' | 'icons' | 'more'>('form');
    const [custom, setCustom] = useState(false);
    const [showPeople, setShowPeople] = useState(false);
    const [saving, setSaving] = useState(false);
    const set = (patch: Partial<Draft>) => setD((x) => ({ ...x, ...patch }));

    // Reset on every open (adjust state on prop change, no effect).
    const [was, setWas] = useState<{ open: boolean; edit: Day | null }>({ open: false, edit: null });
    if (open !== was.open || edit !== was.edit) {
        setWas({ open, edit });
        if (open) {
            setD(
                edit
                    ? {
                          title: edit.title,
                          tone: edit.tone,
                          icon: edit.icon,
                          day: String(edit.day),
                          month: String(edit.month),
                          year: edit.year ? String(edit.year) : '',
                          note: edit.note,
                          personIds: edit.personIds,
                          remind: edit.remindDays,
                          visibility: edit.visibility === 'family' ? 'family' : 'private',
                      }
                    : blank,
            );
            setView('form');
            setCustom(edit?.remindDays != null && !PRESETS.includes(edit.remindDays));
            setShowPeople(!!edit?.personIds.length);
        }
    }

    const icon = d.icon ?? TONE_ICON[d.tone];
    const month = Number(d.month);
    const day = Number(d.day);
    const dated = month >= 1 && day >= 1 && day <= 31;
    const year = Number(d.year) || null;

    // The yellow line (A2): exactly when the reminder arrives, grey when that day already passed this year.
    const preview = (() => {
        if (!dated || d.remind === null) return null;
        const next = nextOn(month, day);
        const at = new Date(next);
        at.setDate(at.getDate() - d.remind);
        const fmt = (x: Date, o: Intl.DateTimeFormatOptions) => x.toLocaleDateString(locale, o);
        if (at < parseDay(localToday()))
            return { passed: true, text: t('days.preview_passed', { date: fmt(at, { month: 'short', day: 'numeric' }) }) };
        const time = new Date(2024, 0, 1, SEND_HOUR).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
        let text = t('days.preview_at', { date: fmt(at, { weekday: 'short', month: 'short', day: 'numeric' }), time });
        const years = year ? next.getFullYear() - year : 0;
        if (d.tone === 'celebrate' && years > 0)
            text += ` ${t('days.preview_years', { years: t('days.n_years', { count: years }), date: fmt(next, { month: 'short', day: 'numeric' }) })}`;
        return { passed: false, text };
    })();

    const save = () => {
        if (!d.title.trim() || !dated || saving) return;
        const body = {
            title: d.title,
            tone: d.tone,
            icon,
            day,
            month,
            year,
            note: d.note,
            person_ids: d.personIds,
            remind_days: d.remind,
            visibility: d.visibility,
        };
        const opts = { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: onClose };
        if (edit) router.patch(route('days.update', edit.id), body, opts);
        else router.post(route('days.store'), body, opts);
    };

    const chip = (on: boolean) =>
        `rounded-full border px-3.5 py-2 text-[15px] font-semibold ${on ? 'bg-ink border-ink text-white' : 'bg-card border-border text-ink'}`;
    const field = 'bg-card border-border text-ink h-10.5 w-full rounded-xl border px-3 text-lg font-semibold outline-none';
    const label = 'text-ink-2 text-sm';

    // A3b / A3c: the icon picker inside the same sheet. This day's feeling first; More adds faith, nature, everyday.
    if (view !== 'form') {
        const groups = iconsFor(d.tone, view === 'more');
        return (
            <ResponsiveModal
                open={open}
                onOpenChange={(v) => !v && onClose()}
                title={t('days.pick_icon')}
                onBack={() => setView('form')}
                backLabel={t(edit ? 'days.edit_title' : 'days.add_title')}
                viewKey={view}
                aside={
                    <button type="button" onClick={() => setView(view === 'more' ? 'icons' : 'more')} className="text-ink-2 text-sm font-semibold">
                        {t(view === 'more' ? 'days.fewer_icons' : 'days.more_icons')}
                    </button>
                }
            >
                <div className="flex flex-col gap-5">
                    {groups.map((g) => (
                        <section key={g} className="flex flex-col gap-2">
                            <h3 className="text-muted-foreground font-mono text-xs font-semibold tracking-widest uppercase">
                                {t(`days.icons_${g}`)}
                            </h3>
                            <IconGrid
                                icons={ICON_GROUPS[g]}
                                value={icon}
                                onPick={(name) => {
                                    set({ icon: name });
                                    setView('form');
                                }}
                            />
                        </section>
                    ))}
                </div>
            </ResponsiveModal>
        );
    }

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={(v) => !v && onClose()}
            title={t(edit ? 'days.edit_title' : 'days.add_title')}
            viewKey="form"
            footer={
                <button
                    type="button"
                    disabled={!d.title.trim() || !dated || saving}
                    onClick={save}
                    className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                >
                    {t('days.save')}
                </button>
            }
        >
            <div className="flex flex-col gap-5">
                {/* Icon + name. Tapping the icon opens A3b. */}
                <div className="flex items-end gap-2">
                    <div className="flex flex-col gap-1">
                        <span className={label}>{t('days.icon')}</span>
                        <button
                            type="button"
                            onClick={() => setView('icons')}
                            aria-label={t('days.pick_icon')}
                            className="bg-card border-border flex size-10.5 items-center justify-center rounded-[14px] border"
                        >
                            <DayIcon name={icon} className="text-ink size-5" />
                        </button>
                    </div>
                    <label className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className={label}>{t('days.what')}</span>
                        <input
                            value={d.title}
                            onChange={(e) => set({ title: e.target.value })}
                            placeholder={t('days.what_placeholder')}
                            maxLength={255}
                            className={`${field} placeholder:text-muted-foreground placeholder:text-base placeholder:font-normal`}
                        />
                    </label>
                </div>

                <div className="flex flex-col gap-2">
                    <span className={label}>{t('days.feel')}</span>
                    <Segmented
                        ariaLabel={t('days.feel')}
                        options={[
                            { value: 'celebrate', label: t('days.celebrate') },
                            { value: 'quiet', label: t('days.quiet') },
                        ]}
                        value={d.tone}
                        onChange={(tone) => set({ tone })}
                    />
                    <p className="text-muted-foreground text-sm">{t(d.tone === 'quiet' ? 'days.quiet_hint' : 'days.celebrate_hint')}</p>
                </div>

                {/* Day and month are enough; the year gives the count. */}
                <div className="grid grid-cols-[1fr_1.4fr_1.2fr] items-start gap-2">
                    <label className="flex flex-col gap-1">
                        <span className={label}>{t('days.day')}</span>
                        <input
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={31}
                            value={d.day}
                            onChange={(e) => set({ day: e.target.value })}
                            className={field}
                        />
                    </label>
                    <div className="flex flex-col gap-1">
                        <span className={label}>{t('days.month')}</span>
                        <Picker
                            ariaLabel={t('days.month')}
                            placeholder={t('days.month')}
                            fallbackIcon={ChevronDown}
                            options={months.map((m, i) => ({ value: String(i + 1), label: m }))}
                            selected={d.month ? [d.month] : []}
                            onChange={(next) => set({ month: next[0] ?? '' })}
                        />
                    </div>
                    <label className="flex flex-col gap-1">
                        <span className={label}>{t('days.year')}</span>
                        <input
                            type="number"
                            inputMode="numeric"
                            min={1900}
                            max={new Date().getFullYear()}
                            value={d.year}
                            placeholder={t('days.optional')}
                            onChange={(e) => set({ year: e.target.value })}
                            className={`${field} placeholder:text-muted-foreground placeholder:text-base placeholder:font-normal`}
                        />
                    </label>
                </div>

                {d.note !== null && (
                    <textarea
                        autoFocus={!edit}
                        value={d.note}
                        onChange={(e) => set({ note: e.target.value })}
                        placeholder={t('days.note_placeholder')}
                        maxLength={500}
                        rows={2}
                        className="bg-background text-ink rounded-xl px-3.5 py-3 text-base outline-none"
                    />
                )}
                {showPeople && (
                    <div className="flex flex-col gap-1">
                        <span className={label}>{t('days.people')}</span>
                        <Picker
                            multi
                            ariaLabel={t('days.people')}
                            placeholder={t('days.people_placeholder')}
                            fallbackIcon={Users}
                            options={people.map((p) => ({ value: String(p.id), label: p.name }))}
                            selected={d.personIds.map(String)}
                            onChange={(next) => set({ personIds: next.map(Number) })}
                        />
                    </div>
                )}
                {/* "+ People" / "+ Note" open their field; ponytail: "+ Photo" from the design is not built yet. */}
                {(!showPeople || d.note === null) && (
                    <div className="flex flex-wrap gap-2">
                        {!showPeople && (
                            <button type="button" onClick={() => setShowPeople(true)} className={chip(false)}>
                                + {t('days.people')}
                            </button>
                        )}
                        {d.note === null && (
                            <button type="button" onClick={() => set({ note: '' })} className={chip(false)}>
                                + {t('days.note')}
                            </button>
                        )}
                    </div>
                )}

                {/* Remind me: off until chosen (design: "Reminders start off, so you choose where you want one"). */}
                <div className="flex flex-col gap-2.5">
                    <span className={label}>{t('days.remind_me')}</span>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            aria-pressed={d.remind === null}
                            onClick={() => {
                                setCustom(false);
                                set({ remind: null });
                            }}
                            className={chip(d.remind === null)}
                        >
                            {t('days.off')}
                        </button>
                        {PRESETS.map((n) => (
                            <button
                                key={n}
                                type="button"
                                aria-pressed={!custom && d.remind === n}
                                onClick={() => {
                                    setCustom(false);
                                    set({ remind: n });
                                }}
                                className={chip(!custom && d.remind === n)}
                            >
                                {remindLabel(t, n)}
                            </button>
                        ))}
                        <button
                            type="button"
                            aria-pressed={custom}
                            onClick={() => {
                                setCustom(true);
                                set({ remind: Math.max(1, d.remind ?? 1) });
                            }}
                            className={chip(custom)}
                        >
                            {t('days.custom')}
                        </button>
                    </div>
                    {custom && d.remind !== null && <Stepper value={d.remind} onChange={(n) => set({ remind: n })} />}
                    {preview && (
                        <p
                            className={`flex items-start gap-2 rounded-xl px-3.5 py-3 text-[15px] ${preview.passed ? 'bg-paper-2 text-muted-foreground' : 'bg-sun-soft text-ink'}`}
                        >
                            {preview.passed ? <BellOff className="mt-0.5 size-4 shrink-0" /> : <Bell className="mt-0.5 size-4 shrink-0" />}
                            {preview.text}
                        </p>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    <span className={label}>{t('days.who_sees')}</span>
                    <Segmented
                        ariaLabel={t('days.who_sees')}
                        options={[
                            { value: 'family', label: t('days.family'), icon: Users },
                            { value: 'private', label: t('days.only_me'), icon: Lock },
                        ]}
                        value={d.visibility}
                        onChange={(visibility) => set({ visibility })}
                    />
                </div>

                {edit && (
                    <button
                        type="button"
                        onClick={() => {
                            onRemove(edit);
                            onClose();
                        }}
                        className="text-destructive flex items-center gap-2 self-start text-sm font-semibold"
                    >
                        <Trash2 className="size-4" />
                        {t('days.remove')}
                    </button>
                )}
            </div>
        </ResponsiveModal>
    );
}

// Custom: 1 to 60 days ahead.
function Stepper({ value, onChange }: { value: number; onChange: (n: number) => void }) {
    const t = useT();
    const btn = 'bg-card text-ink flex size-10 items-center justify-center rounded-full border disabled:opacity-40';
    return (
        <div className="bg-background flex items-center justify-between rounded-2xl px-4 py-2.5">
            <span className="text-primary">{t('days.days_before')}</span>
            <span className="flex items-center gap-3">
                <button type="button" disabled={value <= 1} onClick={() => onChange(value - 1)} aria-label={t('days.fewer')} className={btn}>
                    <Minus className="size-4" />
                </button>
                <span className="text-ink w-6 text-center text-lg font-bold tabular-nums">{value}</span>
                <button type="button" disabled={value >= 60} onClick={() => onChange(value + 1)} aria-label={t('days.more')} className={btn}>
                    <Plus className="size-4" />
                </button>
            </span>
        </div>
    );
}
