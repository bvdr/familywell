import { Head, Link, router } from '@inertiajs/react';
import { Cake, CalendarDays, Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { Fragment, useState } from 'react';

import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useLocale, useT } from '@/lib/i18n';
import { save as saveInBackground } from '@/lib/save';
import type { BreadcrumbItem } from '@/types';

// A birthday row as the birthdays module serializes it. `year`, `age` and `turning` are null when the
// birthday was added without a year (the year is optional by design: the recurring part is month+day).
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
};

// Day/month (+ optional year) inputs shared by quick-add and inline edit. Day and year stay native number
// inputs on purpose: the mobile number keypad beats any custom picker for small numbers.
type Parts = { day: string; month: string; year: string };
const EMPTY: Parts = { day: '', month: '', year: '' };

function monthNames(locale: string): string[] {
    const fmt = new Intl.DateTimeFormat(locale, { month: 'short' });
    return Array.from({ length: 12 }, (_, i) => fmt.format(new Date(2024, i, 1)));
}

function DateParts({ value, onChange, t, months }: { value: Parts; onChange: (v: Parts) => void; t: (k: string) => string; months: string[] }) {
    // h-10.5 matches the Picker control's height so the three fields line up.
    const field = 'border-border text-primary h-10.5 rounded-lg border bg-transparent px-2 text-base md:text-sm';
    return (
        <div className="flex items-start gap-1.5">
            <input
                type="number"
                inputMode="numeric"
                min={1}
                max={31}
                value={value.day}
                onChange={(e) => onChange({ ...value, day: e.target.value })}
                placeholder={t('birthdays.day')}
                aria-label={t('birthdays.day')}
                className={`${field} w-16`}
            />
            {/* Month uses the shared Picker (every dropdown looks the same). It expands inline, so the row
                aligns to the top and the day/year inputs stay put while the list is open. */}
            <div className="w-32 shrink-0">
                <Picker
                    ariaLabel={t('birthdays.month')}
                    placeholder={t('birthdays.month')}
                    fallbackIcon={CalendarDays}
                    options={months.map((m, i) => ({ value: String(i + 1), label: m }))}
                    selected={value.month ? [value.month] : []}
                    onChange={(next) => onChange({ ...value, month: next[0] ?? '' })}
                />
            </div>
            <input
                type="number"
                inputMode="numeric"
                min={1900}
                max={new Date().getFullYear()}
                value={value.year}
                onChange={(e) => onChange({ ...value, year: e.target.value })}
                placeholder={t('birthdays.year')}
                aria-label={t('birthdays.year_optional')}
                className={`${field} w-24`}
            />
        </div>
    );
}

// Diacritics/case-insensitive fuzzy match, so typing "balan", "vbal" or "teodra" finds "Vlad Bălan" /
// "Teodora". Filtering the list while you type the new name is how you spot that someone is already
// in it before adding them twice. ponytail: subsequence match (every query character appears in the
// name, in order) covers skipped letters and initials; add a typo-tolerant scorer only if it's missed.
const fold = (s: string) =>
    s
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s+/g, '');
const fuzzy = (query: string, name: string) => {
    const q = fold(query);
    const n = fold(name);
    let i = 0;
    for (const ch of n) if (ch === q[i]) i++;
    return i === q.length;
};

const payload = (p: Parts) => ({ birth_day: Number(p.day), birth_month: Number(p.month), birth_year: p.year ? Number(p.year) : null });
const complete = (p: Parts) => Number(p.day) >= 1 && Number(p.month) >= 1;

export default function BirthdaysIndex({ birthdays }: { birthdays: Birthday[] }) {
    const t = useT();
    const locale = useLocale();
    const months = monthNames(locale);
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.birthdays'), href: '/m/birthdays' }];
    const [newName, setNewName] = useState('');
    const [newParts, setNewParts] = useState<Parts>(EMPTY);
    const [saving, setSaving] = useState(false);
    const [editing, setEditing] = useState<Birthday | null>(null);

    // Optimistic removal: the row disappears on tap, reappearing only if the delete fails.
    const removed = useOptimistic<boolean>();
    const query = newName.trim();
    const visible = birthdays.filter((b) => !removed.get(b.id, false) && (!query || fuzzy(query, b.name)));
    // Month separators: the list stays soonest-first, so months run from the current one forward and
    // wrap into next year; the header carries the year only once it wraps, to make that visible.
    const monthHeader = (b: Birthday) => {
        const d = new Date(b.nextBirthday + 'T00:00:00');
        const sameYear = d.getFullYear() === new Date().getFullYear();
        return new Intl.DateTimeFormat(locale, sameYear ? { month: 'long' } : { month: 'long', year: 'numeric' }).format(d);
    };
    const remove = (b: Birthday) => {
        if (!confirm(t('birthdays.confirm_remove'))) return;
        removed.set(b.id, true);
        void saveInBackground('delete', route('birthdays.destroy', b.id)).then(() => removed.clear(b.id));
    };

    // Quick-add needs the server (a new person id), so it is not optimistic; the button locks while
    // the request is in flight so a double tap can't add the person twice.
    const add = () => {
        if (!newName.trim() || !complete(newParts) || saving) return;
        router.post(
            route('birthdays.store'),
            { name: newName.trim(), ...payload(newParts) },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setNewName('');
                    setNewParts(EMPTY);
                },
            },
        );
    };

    const when = (b: Birthday) => {
        if (b.daysUntil === 0) return t('birthdays.today');
        if (b.daysUntil === 1) return t('birthdays.tomorrow');
        return t('birthdays.in_days', { days: b.daysUntil });
    };
    const dateLabel = (b: Birthday) => `${b.day} ${months[b.month - 1]}${b.year ? ` ${b.year}` : ''}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.birthdays')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <h1 className="font-heading text-primary text-2xl">{t('nav.birthdays')}</h1>

                {/* Quick add: a name + day/month, year optional. The person becomes a contact too. */}
                <div className="bg-surface-card flex flex-col gap-2 rounded-xl border p-3">
                    <Input
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && add()}
                        placeholder={t('birthdays.add_placeholder')}
                        className="text-base md:text-sm"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <DateParts value={newParts} onChange={setNewParts} t={t} months={months} />
                        <Button
                            size="icon"
                            className="ml-auto shrink-0 rounded-lg"
                            onClick={add}
                            disabled={saving || !newName.trim() || !complete(newParts)}
                            aria-label={t('birthdays.add')}
                        >
                            <Plus className="size-5" />
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('birthdays.add_hint')}</p>
                </div>

                {visible.length === 0 && (
                    <p className="text-muted-foreground py-6 text-center text-sm">{query ? t('birthdays.no_match') : t('birthdays.empty')}</p>
                )}

                <div className="flex flex-col gap-2">
                    {visible.map((b, i) => (
                        <Fragment key={b.id}>
                            {(i === 0 || monthHeader(visible[i - 1]) !== monthHeader(b)) && (
                                <h2 className="text-muted-foreground mt-3 px-1 text-xs font-semibold tracking-widest uppercase first:mt-0">
                                    {monthHeader(b)}
                                </h2>
                            )}
                            {editing?.id === b.id ? (
                                <EditRow birthday={b} months={months} t={t} onClose={() => setEditing(null)} />
                            ) : (
                                <div className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-2.5">
                                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-full">
                                        <Cake className="size-4.5" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <Link href="/m/contacts" className="text-primary block truncate text-[15px]">
                                            {b.name}
                                        </Link>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {dateLabel(b)} · {when(b)}
                                            {b.turning != null ? ` · ${t('birthdays.turns', { age: b.turning })}` : ''}
                                        </span>
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setEditing(b)}
                                        className="text-muted-foreground p-1"
                                        aria-label={t('birthdays.edit')}
                                    >
                                        <Pencil className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => remove(b)}
                                        className="text-muted-foreground p-1"
                                        aria-label={t('birthdays.remove')}
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            )}
                        </Fragment>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

// Inline edit of the day/month/year. Optimistic: the row shows the new date the moment you confirm
// (the props reload lands moments later and is authoritative).
function EditRow({
    birthday,
    months,
    t,
    onClose,
}: {
    birthday: Birthday;
    months: string[];
    t: (k: string, p?: Record<string, string | number>) => string;
    onClose: () => void;
}) {
    const [parts, setParts] = useState<Parts>({
        day: String(birthday.day),
        month: String(birthday.month),
        year: birthday.year ? String(birthday.year) : '',
    });
    const save = () => {
        if (!complete(parts)) return;
        void saveInBackground('patch', route('birthdays.update', birthday.id), payload(parts));
        onClose();
    };
    return (
        <div className="bg-surface-card flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2.5">
            <span className="text-primary min-w-0 flex-1 truncate text-[15px]">{birthday.name}</span>
            <DateParts value={parts} onChange={setParts} t={t} months={months} />
            <button type="button" onClick={save} className="text-primary p-1" aria-label={t('birthdays.save')}>
                <Check className="size-4" />
            </button>
            <button type="button" onClick={onClose} className="text-muted-foreground p-1" aria-label={t('birthdays.cancel')}>
                <X className="size-4" />
            </button>
        </div>
    );
}
