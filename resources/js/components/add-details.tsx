import type { Detail, Member } from '@/components/add-just-say-it';
import { emptyDraft, IconBrowser, ListPicker, NewListForm, newListValue, parseNew, withNew, type NewListDraft } from '@/components/list-picker';
import { MemberAvatar } from '@/components/member-avatar';
import { SettingsGroup, SettingsRow } from '@/components/settings-ui';
import { ymd } from '@/lib/calendar';
import { appLocale, relativeWord, useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';

import {
    Banknote,
    CalendarDays,
    Car,
    ChevronRight,
    Coins,
    Hash,
    ListChecks,
    Repeat2,
    SlidersHorizontal,
    Store,
    Tag,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useRef, useState } from 'react';

// "Add details" on a Just say it card (design XMkKm H2f): that card's full form inside the same Add sheet, the fields
// Classic has for the kind. It only edits the card (nothing is saved until "Add"); Done / the back pill / a swipe down
// return to the cards. Fields come from the server (AddKinds): the card's own ones plus its 'details' ones, drawn by
// type: a date as quick picks, a member as faces, a choice as a row with a picker, an add-on slot as the add-ons' own
// rows (task.editor.fields: Repeat, Important, only when those modules are on), a note under the title.

type Props = {
    item: {
        kind: string;
        target: string | null;
        types: Record<string, string>;
        hidden?: string[];
        people?: string[];
        details?: Record<string, Detail>;
    };
    members: Member[];
    headlineName: string;
    value: (name: string) => string;
    setValue: (name: string, v: string) => void;
    slot: (name: string) => Record<string, unknown>;
    patchSlot: (name: string, patch: Record<string, unknown>) => void;
    label: (name: string) => string;
    field: (name: string) => React.ReactNode; // the card's own input for a plain field (datetime, a multi-row list…)
    rows: (name: string) => { name: string; quantity: string }[]; // a list field's rows (a shopping thing)
    setRows: (name: string, rows: { name: string; quantity: string }[]) => void;
    onDone: () => void;
    onLeaveOut: () => void;
    // The list picker open over the form (design j6QDy4 T3b-d): "list_id" (pick), "list_id/new" (New list) or
    // "list_id/icons" (every icon). Kept by the sheet so its header's back pill steps back one screen.
    picking: string | null;
    setPicking: (name: string | null) => void;
};

// Every row in the group carries an icon, as in the design (Repeat, Important, List).
const ROW_ICON: Record<string, LucideIcon> = {
    list_id: ListChecks,
    category: Tag,
    repeat: Repeat2,
    quantity: Hash,
    shop: Store,
    amount: Banknote,
    currency: Coins,
    plate: Car,
    year: CalendarDays,
};
const chip = (on: boolean) =>
    `flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors ${on ? 'bg-primary border-primary text-primary-foreground' : 'border-border bg-surface-card text-primary'}`;

export function AddDetails({
    item,
    members,
    headlineName,
    value,
    setValue,
    slot,
    patchSlot,
    label,
    field,
    rows,
    setRows,
    onDone,
    onLeaveOut,
    picking,
    setPicking,
}: Props) {
    const t = useT();
    // The New list screen's draft: kept here so the icon browser can step back to it.
    const [newList, setNewList] = useState<NewListDraft>(() => emptyDraft());
    const details = item.details ?? {};
    // The design's layout for every kind (H2f): the title + "Add a note…" in one box, When, Who's on it, then one group
    // of rows (label left, value right) for everything else, then Leave this out and Done. A shopping card is one
    // thing (AddReader splits them), so its row's name is the title and its quantity a row in the group.
    const isList = item.types[headlineName] === 'list';
    const thing = isList ? rows(headlineName) : [];
    const single = isList && thing.length === 1;
    const titled = !isList || single;
    const headline = single ? thing[0].name : value(headlineName);
    const setHeadline = (v: string) => (single ? setRows(headlineName, [{ ...thing[0], name: v }]) : setValue(headlineName, v));
    const notes = Object.keys(details).filter((n) => details[n].type === 'note');
    const all = [...Object.keys(item.types), ...Object.keys(details)].filter(
        (n, i, a) =>
            a.indexOf(n) === i && n !== headlineName && !item.hidden?.includes(n) && !['note', 'choice', 'slot'].includes(details[n]?.type ?? ''),
    );
    const typeOf = (n: string) => details[n]?.type ?? item.types[n];
    const dates = all.filter((n) => typeOf(n) === 'date');
    const people = all.filter((n) => item.people?.includes(n) && !dates.includes(n));
    // datetimes (an event's start / end) and multi-row lists keep the card's own input; plain text / numbers are rows.
    const own = all.filter((n) => !dates.includes(n) && !people.includes(n) && ['datetime', 'list'].includes(typeOf(n)));
    const plain = all.filter((n) => !dates.includes(n) && !people.includes(n) && !own.includes(n));
    const hasQty = single && (item as { quantity?: string[] }).quantity?.includes(headlineName);
    const slots = Object.keys(details).filter((n) => details[n].type === 'slot');
    const choices = Object.keys(details).filter((n) => details[n].type === 'choice');

    const [pf, stage] = (picking ?? '').split('/');
    if (picking && details[pf]) {
        if (stage === 'icons') {
            return (
                <IconBrowser
                    name={newList.name}
                    value={newList.icon}
                    onPick={(icon) => {
                        setNewList({ ...newList, icon, iconPicked: true });
                        setPicking(`${pf}/new`);
                    }}
                />
            );
        }
        if (stage === 'new') {
            return (
                <NewListForm
                    draft={newList}
                    setDraft={setNewList}
                    note={t('add_details.goes_here', { title: headline })}
                    submitLabel={t('add_details.create_list_button')}
                    onMoreIcons={() => setPicking(`${pf}/icons`)}
                    onSubmit={() => {
                        // Made on Add, with the rest of the card.
                        setValue(pf, newListValue(newList));
                        setPicking(null);
                    }}
                />
            );
        }
        return (
            <ListPicker
                options={withNew(details[pf].choices ?? [], value(pf))}
                // Nothing picked yet: the list it lands in now (the guess) is the ticked one.
                value={value(pf) || (details[pf].choices ?? []).find((o) => o.label === item.target)?.value || ''}
                onPick={(v) => {
                    setValue(pf, v);
                    setPicking(null);
                }}
                onNew={(name) => {
                    setNewList(parseNew(value(pf)) ?? emptyDraft(name));
                    setPicking(`${pf}/new`);
                }}
                newSub={t('add_details.new_list_sub_todo')}
            />
        );
    }

    return (
        <div className="flex flex-col gap-5">
            {!titled && field(headlineName)}
            {(titled || notes.length > 0) && (
                <div className="bg-surface flex flex-col gap-1 rounded-2xl px-4 py-3">
                    {titled && (
                        <input
                            value={headline}
                            onChange={(e) => setHeadline(e.target.value)}
                            placeholder={label(headlineName)}
                            aria-label={label(headlineName)}
                            // 16px+ so iOS doesn't zoom in on focus.
                            className="text-primary placeholder:text-muted-foreground w-full bg-transparent text-xl font-semibold outline-none"
                        />
                    )}
                    {notes.map((n) => (
                        <textarea
                            key={n}
                            value={value(n)}
                            onChange={(e) => setValue(n, e.target.value)}
                            rows={1}
                            placeholder={t('add_details.note_placeholder')}
                            aria-label={label(n)}
                            className="text-primary placeholder:text-muted-foreground field-sizing-content w-full resize-none bg-transparent text-base outline-none"
                        />
                    ))}
                </div>
            )}

            {dates.map((n) => (
                <When key={n} value={value(n)} onChange={(v) => setValue(n, v)} />
            ))}
            {people.map((n) => (
                <Who key={n} members={members} value={value(n)} onChange={(v) => setValue(n, v)} />
            ))}
            {own.map((n) => (
                <div key={n}>{field(n)}</div>
            ))}

            {(slots.length > 0 || choices.length > 0 || plain.length > 0 || hasQty) && (
                <SettingsGroup>
                    {/* Plain values as rows: label left, the value typed in on the right (same column as the pickers). */}
                    {hasQty && (
                        <InputRow
                            icon={Hash}
                            label={t('add.f_quantity')}
                            value={thing[0].quantity}
                            onChange={(v) => setRows(headlineName, [{ ...thing[0], quantity: v }])}
                        />
                    )}
                    {plain.map((n) => (
                        <InputRow
                            key={n}
                            icon={ROW_ICON[n] ?? SlidersHorizontal}
                            label={label(n)}
                            value={value(n)}
                            numeric={typeOf(n) === 'number'}
                            onChange={(v) => setValue(n, v)}
                        />
                    ))}
                    {/* The add-ons' controls, the same Slot the Classic task editor places; variant 'row' draws them as
                        rows here, and onPatch only keeps their values on the card (sent to task.saved on Add). */}
                    {slots.map((n) => (
                        <Slot
                            key={n}
                            name={details[n].slot!}
                            ctx={{ task: slot(n), onPatch: (p: Record<string, unknown>) => patchSlot(n, p), variant: 'row' }}
                        />
                    ))}
                    {choices.map((n) => {
                        const options = withNew(details[n].choices ?? [], value(n));
                        // Unpicked: the option meaning "nothing picked" (value ''), else where the card lands now (its
                        // target, for a list), else the first.
                        const current =
                            value(n) || (options.find((o) => o.value === '') ?? options.find((o) => o.label === item.target) ?? options[0])?.value;
                        const Icon = ROW_ICON[n] ?? SlidersHorizontal;
                        // Lists (choices that carry when they last changed) open the picker in the sheet.
                        if (options.some((o) => o.updated_at)) {
                            return (
                                <SettingsRow
                                    key={n}
                                    icon={Icon}
                                    title={label(n)}
                                    value={<span className="text-primary font-semibold">{options.find((o) => o.value === current)?.label}</span>}
                                    onClick={() => setPicking(n)}
                                    className="order-2"
                                />
                            );
                        }
                        return (
                            // A native select laid over the row: the phone's own picker opens on a tap (the wheel on iOS).
                            <div key={n} className="relative order-2 border-b last:border-b-0">
                                <SettingsRow
                                    icon={Icon}
                                    title={label(n)}
                                    value={<span className="text-primary font-semibold">{options.find((o) => o.value === current)?.label}</span>}
                                    right={<ChevronRight className="text-muted-foreground size-5 shrink-0" />}
                                    className="border-b-0"
                                />
                                <select
                                    value={current ?? ''}
                                    onChange={(e) => setValue(n, e.target.value)}
                                    aria-label={label(n)}
                                    className="absolute inset-0 size-full cursor-pointer opacity-0"
                                >
                                    {options.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        );
                    })}
                </SettingsGroup>
            )}

            <div className="flex items-center justify-between gap-3">
                <button type="button" onClick={onLeaveOut} className="text-destructive flex min-h-11 items-center gap-2 text-base font-semibold">
                    <Trash2 className="size-5" />
                    {t('add_details.leave_out')}
                </button>
                <span className="text-muted-foreground text-right text-sm">{t('add_details.from_typed')}</span>
            </div>

            <button
                type="button"
                onClick={onDone}
                className="bg-accent-strong text-accent-strong-foreground border-primary rounded-full border-2 py-3.5 text-lg font-bold"
            >
                {t('common.done')}
            </button>
        </div>
    );
}

// "When" (design vsI6t): Today, Tomorrow, then a date chip: the picked day with a calendar ("Sat, Oct 24"), or Pick…
// before one is picked; it opens the native date picker. Tapping a picked Today / Tomorrow again clears it.
export function When({ value, onChange }: { value: string; onChange: (v: string) => void }) {
    const t = useT();
    const pick = useRef<HTMLInputElement>(null);
    const day = value.slice(0, 10);
    const today = ymd(new Date());
    const tomorrow = ymd(new Date(Date.now() + 864e5));
    const other = !!day && day !== today && day !== tomorrow;
    const set = (d: string) => onChange(day === d ? '' : d);
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-primary text-sm font-semibold">{t('add_details.when')}</h3>
            {/* One row that swipes sideways when it can't fit (a long date or a long "Tomorrow" in another language). */}
            <div data-scroll-x className="-mx-5 flex gap-1.5 overflow-x-auto px-5 [scrollbar-width:none] md:mx-0 md:px-0 [&>*]:shrink-0">
                <button type="button" onClick={() => set(today)} className={chip(day === today)}>
                    {relativeWord(0, 'day')}
                </button>
                <button type="button" onClick={() => set(tomorrow)} className={chip(day === tomorrow)}>
                    {relativeWord(1, 'day')}
                </button>
                <span className="relative">
                    <button
                        type="button"
                        onClick={() => (pick.current?.showPicker ? pick.current.showPicker() : pick.current?.focus())}
                        className={chip(other)}
                    >
                        <CalendarDays className={`size-4 ${other ? 'text-accent-strong' : ''}`} />
                        {other
                            ? new Date(`${day}T00:00:00`).toLocaleDateString(appLocale(), { weekday: 'short', month: 'short', day: 'numeric' })
                            : t('add_details.pick')}
                    </button>
                    {/* Under the chip, not display:none: showPicker() needs a rendered input. */}
                    <input
                        ref={pick}
                        type="date"
                        value={day}
                        onChange={(e) => onChange(e.target.value)}
                        tabIndex={-1}
                        aria-hidden
                        className="pointer-events-none absolute inset-0 -z-10 opacity-0"
                    />
                </span>
            </div>
        </section>
    );
}

// "Who's on it": Anyone, then each member's face. The value is the member's name, like the reader's "who".
function Who({ members, value, onChange }: { members: Member[]; value: string; onChange: (v: string) => void }) {
    const t = useT();
    const v = value.trim().toLowerCase();
    const isPicked = (m: Member) => !!v && (m.name.toLowerCase() === v || m.name.split(' ')[0].toLowerCase() === v);
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-primary text-sm font-semibold">{t('add_details.who_on_it')}</h3>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={() => onChange('')}
                    aria-pressed={!v}
                    className={`min-h-9 rounded-full px-4 text-sm font-semibold ${!v ? 'bg-primary text-primary-foreground' : 'bg-surface text-primary'}`}
                >
                    {t('add_details.anyone')}
                </button>
                {members.map((m) => (
                    <button
                        key={m.id}
                        type="button"
                        onClick={() => onChange(m.name)}
                        aria-pressed={isPicked(m)}
                        aria-label={m.name}
                        className={`rounded-full ${isPicked(m) ? 'ring-primary ring-offset-background ring-2 ring-offset-2' : ''}`}
                    >
                        <MemberAvatar userId={m.id} name={m.name} className="size-9 text-sm" />
                    </button>
                ))}
            </div>
        </section>
    );
}

// A plain value as a settings row (the form-row rule): the label left, the value typed in on the right.
function InputRow({
    icon: Icon,
    label,
    value,
    onChange,
    numeric,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    onChange: (v: string) => void;
    numeric?: boolean;
}) {
    return (
        <label className="flex min-h-14 items-center gap-3.5 border-b px-4 py-2 last:border-b-0">
            <Icon className="text-primary size-5 shrink-0" />
            <span className="text-primary shrink-0 text-[17px]">{label}</span>
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                inputMode={numeric ? 'decimal' : undefined}
                placeholder="—"
                // 16px+ so iOS doesn't zoom in on focus; right-aligned like the other rows' values.
                className="text-primary placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-right text-[17px] font-semibold outline-none"
            />
        </label>
    );
}
