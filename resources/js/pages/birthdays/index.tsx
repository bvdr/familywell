import { Head, router } from '@inertiajs/react';
import { Bell, BellOff, Cake, CalendarDays, Check, ChevronRight, Clock, Minus, Plus, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';

import { Picker } from '@/components/picker';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { localToday } from '@/lib/date-groups';
import { useLocale, useT } from '@/lib/i18n';
import { save as saveInBackground } from '@/lib/save';
import type { BreadcrumbItem } from '@/types';

// A birthday row as the birthdays module serializes it. `year`, `age` and `turning` are null when the
// birthday was added without a year (the year is optional by design: the recurring part is month+day).
// remindDays: this person's reminder, days ahead (0 = on the day); null = the family default.
type Birthday = {
    id: number;
    personId: number;
    name: string;
    month: number;
    day: number;
    year: number | null;
    nextBirthday: string;
    daysUntil: number;
    age: number | null;
    turning: number | null;
    remindDays: number | null;
};
type Defaults = { remindDays: number; sendHour: number };
type Person = { id: number; name: string };
type T = ReturnType<typeof useT>;

// The reminder presets (design 10b M6); anything else is Custom (M7's 1-60 stepper).
const PRESETS = [0, 1, 3, 7, 14];

// "On the day" / "1 day" / "3 days" / "1 week" / "2 weeks": the chip and bell labels.
function remindLabel(t: T, n: number): string {
    if (n === 0) return t('birthdays.r_on_day');
    if (n % 7 === 0) return t('birthdays.n_weeks', { count: n / 7 });
    return t('birthdays.n_days', { count: n });
}
// The title pill and M11's rows: "3 days before" (on the day stays "On the day").
const beforeLabel = (t: T, n: number) => (n === 0 ? t('birthdays.r_on_day') : t('birthdays.r_before', { label: remindLabel(t, n) }));

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join('');

function monthNames(locale: string, style: 'short' | 'long' = 'short'): string[] {
    const fmt = new Intl.DateTimeFormat(locale, { month: style });
    return Array.from({ length: 12 }, (_, i) => fmt.format(new Date(2024, i, 1)));
}

// Diacritics/case-insensitive fuzzy match, so typing "balan", "vbal" or "teodra" finds "Vlad Bălan" / "Teodora".
// ponytail: subsequence match (every query character appears in the name, in order); add a typo-tolerant
// scorer only if it's missed.
const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, '');
const fuzzy = (query: string, name: string) => {
    const q = fold(query);
    let i = 0;
    for (const ch of fold(name)) if (ch === q[i]) i++;
    return i === q.length;
};

// The next birthday on/after today for a day/month, as a local Date (Feb 29 rolls to Mar 1 off leap years,
// like the server's nextBirthday()).
function nextOn(month: number, day: number): Date {
    const today = new Date(localToday() + 'T00:00:00');
    const d = new Date(today.getFullYear(), month - 1, day);
    if (d < today) d.setFullYear(d.getFullYear() + 1, month - 1, day);
    return d;
}

export default function BirthdaysIndex({ birthdays, defaults, people }: { birthdays: Birthday[]; defaults: Defaults; people: Person[] }) {
    const t = useT();
    const locale = useLocale();
    const months = monthNames(locale);
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.birthdays'), href: '/m/birthdays' }];
    // One sheet for add (starting at Find, M5) and edit (M10, straight to the form).
    // ?date=MM-DD: the calendar's right-click › Birthday on <day> (design 04b D3) lands here with the add sheet open
    // and that day and month filled in (the year stays the person's to type).
    const [arrival] = useState(() => {
        const m = new URLSearchParams(window.location.search).get('date')?.match(/^(\d{2})-(\d{2})$/);
        return m ? { month: String(Number(m[1])), day: String(Number(m[2])) } : null;
    });
    const [sheet, setSheet] = useState<{ open: boolean; edit: Birthday | null }>({ open: !!arrival, edit: null });
    const [settingsOpen, setSettingsOpen] = useState(false);
    useAddAction(() => setSheet({ open: true, edit: null }), { label: 'birthdays.add', icon: Cake });

    const removed = useOptimistic<boolean>();
    const visible = birthdays.filter((b) => !removed.get(b.id, false));
    const today = visible.filter((b) => b.daysUntil === 0);
    const rest = visible.filter((b) => b.daysUntil > 0);
    const monthHeader = (b: Birthday) => {
        const d = new Date(b.nextBirthday + 'T00:00:00');
        const sameYear = d.getFullYear() === new Date().getFullYear();
        return new Intl.DateTimeFormat(locale, sameYear ? { month: 'long' } : { month: 'long', year: 'numeric' }).format(d);
    };
    // Soonest first already, so months run from this one forward and wrap into next year.
    const groups: [string, Birthday[]][] = [];
    for (const b of rest) {
        const m = monthHeader(b);
        if (groups.at(-1)?.[0] === m) groups.at(-1)![1].push(b);
        else groups.push([m, [b]]);
    }
    const inDays = (n: number) => (n === 1 ? t('birthdays.tomorrow') : t('birthdays.in_n_days', { count: n }));
    const remove = (b: Birthday) => {
        removed.set(b.id, true);
        void saveInBackground('delete', route('birthdays.destroy', b.id)).then(() => removed.clear(b.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.birthdays')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                {/* Title, and the family's reminder default as a pill (M4 → M11). */}
                <div className="flex items-center justify-between gap-3">
                    <h1 className="font-heading text-primary min-w-0 truncate text-4xl font-extrabold tracking-tight">{t('nav.birthdays')}</h1>
                    {/* The + works on desktop too, and on phones when the bottom + belongs to "Just say it". */}
                    <div className="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setSettingsOpen(true)}
                            aria-label={`${t('birthdays.reminders')}: ${beforeLabel(t, defaults.remindDays)}`}
                            className="bg-paper-2 text-ink flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold"
                        >
                            <Bell className="size-4" />
                            {/* Phones get the short form ("3 days"): the full "3 days before" pushed the + off screen at 390px. */}
                            <span className="sm:hidden">{remindLabel(t, defaults.remindDays)}</span>
                            <span className="hidden sm:inline">{beforeLabel(t, defaults.remindDays)}</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setSheet({ open: true, edit: null })}
                            aria-label={t('birthdays.add')}
                            className="bg-paper-2 text-ink flex size-10 items-center justify-center rounded-full"
                        >
                            <Plus className="size-5" />
                        </button>
                    </div>
                </div>

                {/* Today gets the sun. */}
                {today.map((b) => (
                    <button
                        key={b.id}
                        type="button"
                        onClick={() => setSheet({ open: true, edit: b })}
                        className="bg-sun text-ink flex items-center gap-3.5 rounded-[22px] p-4 text-left"
                    >
                        <span className="bg-card flex size-12 shrink-0 items-center justify-center rounded-2xl">
                            <Cake className="size-6" />
                        </span>
                        <span className="min-w-0">
                            <span className="text-ink-2 block font-mono text-xs font-semibold tracking-widest">{t('birthdays.today_label')}</span>
                            <span className="block truncate text-lg font-bold">
                                {b.turning != null
                                    ? `${b.name} ${t('birthdays.turns', { age: b.turning })}`
                                    : t('birthdays.today_noage', { name: b.name })}
                            </span>
                        </span>
                    </button>
                ))}

                {visible.length === 0 && (
                    <div className="flex flex-col items-center gap-3 py-10 text-center">
                        <p className="text-muted-foreground text-sm">{t('birthdays.empty')}</p>
                        <button
                            type="button"
                            onClick={() => setSheet({ open: true, edit: null })}
                            className="bg-accent-strong text-accent-strong-foreground rounded-full px-5 py-2.5 font-semibold"
                        >
                            {t('birthdays.add')}
                        </button>
                    </div>
                )}

                {/* The rest by month, soonest first, one card per month. The date tile turns sun-soft (and the line
                    warm) once the reminder is due within a day or has gone out; the bell is ink when this person has
                    their own reminder, muted on the family default. */}
                {groups.map(([month, rows]) => (
                    <section key={month} className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-widest uppercase">{month}</h2>
                        <div className="bg-card overflow-hidden rounded-[22px] border">
                            {rows.map((b) => {
                                const remind = b.remindDays ?? defaults.remindDays;
                                const soon = b.daysUntil <= remind + 1;
                                return (
                                    <button
                                        key={b.id}
                                        type="button"
                                        onClick={() => setSheet({ open: true, edit: b })}
                                        className="flex w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0"
                                    >
                                        <span
                                            className={`flex size-12 shrink-0 flex-col items-center justify-center rounded-2xl ${soon ? 'bg-sun-soft' : 'bg-paper-2'}`}
                                        >
                                            <span className="text-muted-foreground font-mono text-[10px] font-semibold uppercase">
                                                {months[b.month - 1]}
                                            </span>
                                            <span className="text-ink text-lg leading-none font-bold">{b.day}</span>
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-[17px] font-semibold">{b.name}</span>
                                            <span
                                                className={`block truncate text-sm ${soon ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}
                                            >
                                                {b.turning != null ? `${t('birthdays.turns', { age: b.turning })} · ` : ''}
                                                {inDays(b.daysUntil)}
                                            </span>
                                        </span>
                                        <span
                                            className={`flex shrink-0 items-center gap-1 text-sm ${b.remindDays != null ? 'text-ink font-semibold' : 'text-muted-foreground'}`}
                                        >
                                            <Bell className="size-3.5" />
                                            {remindLabel(t, remind)}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>
                ))}
            </div>

            <BirthdaySheet
                open={sheet.open}
                edit={sheet.edit}
                onClose={() => setSheet((s) => ({ ...s, open: false }))}
                onEdit={(b) => setSheet({ open: true, edit: b })}
                onRemove={remove}
                birthdays={birthdays}
                people={people}
                defaults={defaults}
                months={months}
                arrival={arrival}
            />
            <SettingsSheet
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                defaults={defaults}
                birthdays={visible}
                onEdit={(b) => {
                    setSettingsOpen(false);
                    setSheet({ open: true, edit: b });
                }}
            />
        </AppLayout>
    );
}

// Add (M5 Find → M6 form, M8 for a new contact) and edit (M10) in one sheet. Find searches Contacts so nobody is added
// twice; someone who already has a birthday opens it for editing instead.
function BirthdaySheet({
    open,
    edit,
    onClose,
    onEdit,
    onRemove,
    birthdays,
    people,
    defaults,
    months,
    arrival,
}: {
    open: boolean;
    edit: Birthday | null;
    onClose: () => void;
    onEdit: (b: Birthday) => void;
    onRemove: (b: Birthday) => void;
    birthdays: Birthday[];
    people: Person[];
    defaults: Defaults;
    months: string[];
    arrival: { day: string; month: string } | null; // a new birthday's day and month, from ?date=
}) {
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState('');
    // Who it's for: a contact (id) or a new one (name only). null = still on Find.
    const [who, setWho] = useState<{ id: number | null; name: string } | null>(null);
    const [parts, setParts] = useState({ day: '', month: '', year: '' });
    const [remind, setRemind] = useState<number | null>(null); // null = family default
    const [custom, setCustom] = useState(false);
    const [saving, setSaving] = useState(false);

    // Reset on every open (React's "adjust state on prop change", no effect).
    const [was, setWas] = useState<{ open: boolean; edit: Birthday | null }>({ open: false, edit: null });
    if (open !== was.open || edit !== was.edit) {
        setWas({ open, edit });
        if (open) {
            setQuery('');
            setWho(edit ? { id: edit.personId, name: edit.name } : null);
            setParts(
                edit
                    ? { day: String(edit.day), month: String(edit.month), year: edit.year ? String(edit.year) : '' }
                    : { day: arrival?.day ?? '', month: arrival?.month ?? '', year: '' },
            );
            setRemind(edit?.remindDays ?? null);
            setCustom(edit?.remindDays != null && !PRESETS.includes(edit.remindDays));
        }
    }

    const byPerson = new Map(birthdays.map((b) => [b.personId, b]));
    const q = query.trim();
    const matches = q ? people.filter((p) => fuzzy(q, p.name)).slice(0, 6) : [];
    const exact = people.some((p) => p.name.toLowerCase() === q.toLowerCase());

    const effective = remind ?? defaults.remindDays;
    const month = Number(parts.month);
    const day = Number(parts.day);
    const dated = month >= 1 && day >= 1 && day <= 31;
    const year = Number(parts.year) || null;

    // The yellow line (M6/M7): exactly when the reminder arrives; grey when that day already passed this year (M7b).
    const preview = (() => {
        if (!dated) return null;
        const next = nextOn(month, day);
        const at = new Date(next);
        at.setDate(at.getDate() - effective);
        const today = new Date(localToday() + 'T00:00:00');
        const fmt = (d: Date) => d.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' });
        if (at < today)
            return { passed: true, text: t('birthdays.preview_passed', { date: at.toLocaleDateString(locale, { month: 'short', day: 'numeric' }) }) };
        const time = new Date(2024, 0, 1, defaults.sendHour).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
        // On the day it's the morning birthday wish, not a separate reminder.
        const when = effective === 0 ? t('birthdays.preview_day', { date: fmt(at) }) : t('birthdays.preview_at', { date: fmt(at), time });
        const turns = year ? ` ${t('birthdays.preview_turns', { name: who?.name.split(' ')[0] ?? '', age: next.getFullYear() - year })}` : '';
        return { passed: false, text: when + turns };
    })();

    const save = () => {
        if (!who || !dated || saving) return;
        const body = { birth_day: day, birth_month: month, birth_year: year, remind_days: remind };
        const opts = { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: onClose };
        if (edit) router.patch(route('birthdays.update', edit.id), body, opts);
        else router.post(route('birthdays.store'), who.id ? { ...body, person_id: who.id } : { ...body, name: who.name }, opts);
    };

    const chip = (on: boolean) =>
        `rounded-full border px-3.5 py-2 text-[15px] font-semibold ${on ? 'bg-ink border-ink text-white' : 'bg-card border-border text-ink'}`;
    // h-10.5 = the Picker control's height, so Day / Month / Year line up.
    const field = 'bg-card border-border text-ink h-10.5 w-full rounded-xl border px-3 text-lg font-semibold outline-none';

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={(v) => !v && onClose()}
            title={edit ? t('birthdays.their_birthday', { name: edit.name }) : t('birthdays.add_title')}
            footer={
                who && (
                    <button
                        type="button"
                        disabled={!dated || saving}
                        onClick={save}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t(edit ? 'birthdays.save_changes' : 'birthdays.save_birthday')}
                    </button>
                )
            }
        >
            {!who ? (
                // M5: find the person.
                <div className="flex flex-col gap-3">
                    <label className="text-muted-foreground text-sm font-semibold">{t('birthdays.add_placeholder')}</label>
                    <input autoFocus value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('birthdays.name')} className={field} />
                    <div className="flex flex-col">
                        {matches.map((p) => {
                            const has = byPerson.get(p.id);
                            return (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => (has ? onEdit(has) : setWho({ id: p.id, name: p.name }))}
                                    className="flex items-center gap-3 border-b py-2.5 text-left last:border-b-0"
                                >
                                    <span className="bg-paper-2 text-ink flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-bold">
                                        {initials(p.name)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block truncate font-semibold">{p.name}</span>
                                        <span className="text-muted-foreground block truncate text-sm">
                                            {has
                                                ? t('birthdays.contact_has', { date: `${months[has.month - 1]} ${has.day}` })
                                                : t('birthdays.contact_none')}
                                        </span>
                                    </span>
                                    <ChevronRight className={`size-5 shrink-0 ${has ? 'text-muted-foreground' : 'text-ink'}`} />
                                </button>
                            );
                        })}
                        {q && !exact && (
                            <button type="button" onClick={() => setWho({ id: null, name: q })} className="flex items-center gap-3 py-2.5 text-left">
                                <span className="bg-sun text-ink flex size-10 shrink-0 items-center justify-center rounded-full">
                                    <UserPlus className="size-5" />
                                </span>
                                <span className="text-primary font-semibold">{t('birthdays.add_new', { name: q })}</span>
                            </button>
                        )}
                    </div>
                </div>
            ) : (
                // M6 / M7 / M7b / M8 / M10: the date and the reminder.
                <div className="flex flex-col gap-5">
                    <div className="bg-background flex items-center gap-3 rounded-2xl p-3">
                        <span className="bg-paper-2 text-ink flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-bold">
                            {initials(who.name)}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="text-primary block truncate font-semibold">{who.name}</span>
                            <span className="text-muted-foreground block text-sm">{t(who.id ? 'birthdays.contact' : 'birthdays.new_contact')}</span>
                        </span>
                        {!edit && (
                            <button type="button" onClick={() => setWho(null)} className="text-primary text-sm font-semibold">
                                {t('birthdays.change')}
                            </button>
                        )}
                    </div>

                    {/* Day and month are enough; the year adds their age. */}
                    <div className="grid grid-cols-[1fr_1.4fr_1.2fr] items-start gap-2">
                        <label className="flex flex-col gap-1">
                            <span className="text-muted-foreground text-sm">{t('birthdays.day')}</span>
                            <input
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={31}
                                value={parts.day}
                                onChange={(e) => setParts({ ...parts, day: e.target.value })}
                                className={field}
                            />
                        </label>
                        <div className="flex flex-col gap-1">
                            <span className="text-muted-foreground text-sm">{t('birthdays.month')}</span>
                            <Picker
                                ariaLabel={t('birthdays.month')}
                                placeholder={t('birthdays.month')}
                                fallbackIcon={CalendarDays}
                                options={months.map((m, i) => ({ value: String(i + 1), label: m }))}
                                selected={parts.month ? [parts.month] : []}
                                onChange={(next) => setParts({ ...parts, month: next[0] ?? '' })}
                            />
                        </div>
                        <label className="flex flex-col gap-1">
                            <span className="text-muted-foreground text-sm">{t('birthdays.year')}</span>
                            <input
                                type="number"
                                inputMode="numeric"
                                min={1900}
                                max={new Date().getFullYear()}
                                value={parts.year}
                                placeholder={t('birthdays.optional')}
                                onChange={(e) => setParts({ ...parts, year: e.target.value })}
                                className={`${field} placeholder:text-muted-foreground placeholder:text-base placeholder:font-normal`}
                            />
                        </label>
                    </div>

                    {/* Remind me: starts on the family default; any other pick is this person's own, and Use default undoes it. */}
                    <div className="flex flex-col gap-2.5">
                        <div className="flex items-center justify-between">
                            <span className="text-primary font-semibold">{t('birthdays.remind_me')}</span>
                            {remind === null ? (
                                <span className="text-muted-foreground text-sm">{t('birthdays.family_default')}</span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setRemind(null);
                                        setCustom(false);
                                    }}
                                    className="text-primary text-sm font-semibold underline"
                                >
                                    {t('birthdays.use_default')}
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {PRESETS.map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    aria-pressed={!custom && effective === n}
                                    onClick={() => {
                                        setCustom(false);
                                        setRemind(n === defaults.remindDays ? null : n);
                                    }}
                                    className={chip(!custom && effective === n)}
                                >
                                    {remindLabel(t, n)}
                                </button>
                            ))}
                            <button
                                type="button"
                                aria-pressed={custom}
                                onClick={() => {
                                    setCustom(true);
                                    setRemind(Math.max(1, effective));
                                }}
                                className={chip(custom)}
                            >
                                {t('birthdays.custom')}
                            </button>
                        </div>
                        {custom && <Stepper label={t('birthdays.days_before')} value={effective} onChange={(n) => setRemind(n)} />}
                        {preview && (
                            <p
                                className={`flex items-start gap-2 rounded-2xl px-3.5 py-3 text-[15px] ${preview.passed ? 'bg-paper-2 text-muted-foreground' : 'bg-sun-soft text-ink'}`}
                            >
                                {preview.passed ? <BellOff className="mt-0.5 size-4 shrink-0" /> : <Bell className="mt-0.5 size-4 shrink-0" />}
                                {preview.text}
                            </p>
                        )}
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
                            {t('birthdays.remove')}
                        </button>
                    )}
                </div>
            )}
        </ResponsiveModal>
    );
}

// M7's minus/plus: 1 to 60 days.
function Stepper({ label, value, onChange }: { label: string; value: number; onChange: (n: number) => void }) {
    const t = useT();
    const btn = 'bg-card text-ink flex size-10 items-center justify-center rounded-full border disabled:opacity-40';
    return (
        <div className="bg-background flex items-center justify-between rounded-2xl px-4 py-2.5">
            <span className="text-primary">{label}</span>
            <span className="flex items-center gap-3">
                <button type="button" disabled={value <= 1} onClick={() => onChange(value - 1)} aria-label={t('birthdays.fewer')} className={btn}>
                    <Minus className="size-4" />
                </button>
                <span className="text-ink w-6 text-center text-lg font-bold tabular-nums">{value}</span>
                <button type="button" disabled={value >= 60} onClick={() => onChange(value + 1)} aria-label={t('birthdays.more')} className={btn}>
                    <Plus className="size-4" />
                </button>
            </span>
        </div>
    );
}

// M11: the family default. Each tap saves; anyone set differently is listed so nothing gets lost.
function SettingsSheet({
    open,
    onOpenChange,
    defaults,
    birthdays,
    onEdit,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    defaults: Defaults;
    birthdays: Birthday[];
    onEdit: (b: Birthday) => void;
}) {
    const t = useT();
    const locale = useLocale();
    const [custom, setCustom] = useState(false);
    const isCustom = custom || !PRESETS.includes(defaults.remindDays);
    const put = (patch: Partial<{ remind_days: number; send_hour: number }>) =>
        router.patch(
            route('birthdays.settings'),
            { remind_days: defaults.remindDays, send_hour: defaults.sendHour, ...patch },
            { preserveScroll: true, preserveState: true },
        );
    const hour = (h: number) => new Date(2024, 0, 1, h).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' });
    const different = birthdays.filter((b) => b.remindDays != null && b.remindDays !== defaults.remindDays);
    const row = 'flex min-h-12 w-full items-center gap-3 border-b px-4 py-2.5 text-left last:border-b-0';
    const card = 'bg-card overflow-hidden rounded-[22px] border';
    const head = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-widest uppercase';

    return (
        <ResponsiveModal open={open} onOpenChange={onOpenChange} title={t('birthdays.reminders')}>
            <div className="flex flex-col gap-5">
                <section className="flex flex-col gap-2">
                    <h3 className={head}>{t('birthdays.how_far')}</h3>
                    <div className={card}>
                        {PRESETS.map((n) => (
                            <button
                                key={n}
                                type="button"
                                onClick={() => {
                                    setCustom(false);
                                    put({ remind_days: n });
                                }}
                                className={row}
                            >
                                <span className="text-primary flex-1">{beforeLabel(t, n)}</span>
                                {!isCustom && defaults.remindDays === n && <Check className="text-primary size-5" />}
                            </button>
                        ))}
                        <button type="button" onClick={() => setCustom(true)} className={row}>
                            <span className="text-primary flex-1">{t('birthdays.custom')}</span>
                            {isCustom && <Check className="text-primary size-5" />}
                        </button>
                    </div>
                    {isCustom && (
                        <Stepper
                            label={t('birthdays.days_before')}
                            value={Math.max(1, defaults.remindDays)}
                            onChange={(n) => put({ remind_days: n })}
                        />
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h3 className={head}>{t('birthdays.when')}</h3>
                    <div className={`${card} flex items-center gap-3 px-4 py-2`}>
                        <span className="text-primary flex-1">{t('birthdays.send_at')}</span>
                        <div className="w-40 shrink-0">
                            <Picker
                                ariaLabel={t('birthdays.send_at')}
                                fallbackIcon={Clock}
                                options={Array.from({ length: 16 }, (_, i) => i + 6).map((h) => ({ value: String(h), label: hour(h) }))}
                                selected={[String(defaults.sendHour)]}
                                onChange={(next) => next[0] && put({ send_hour: Number(next[0]) })}
                            />
                        </div>
                    </div>
                </section>

                {different.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h3 className={head}>{t('birthdays.set_differently')}</h3>
                        <div className={card}>
                            {different.map((b) => (
                                <button key={b.id} type="button" onClick={() => onEdit(b)} className={row}>
                                    <span className="text-primary min-w-0 flex-1 truncate">{b.name}</span>
                                    <span className="text-muted-foreground text-sm">{remindLabel(t, b.remindDays!)}</span>
                                    <ChevronRight className="text-muted-foreground size-4" />
                                </button>
                            ))}
                        </div>
                    </section>
                )}

                <p className="text-muted-foreground text-sm">{t('birthdays.reminders_note')}</p>
            </div>
        </ResponsiveModal>
    );
}
