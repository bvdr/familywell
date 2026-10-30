import { AddDetails } from '@/components/add-details';
import { useVoice, voiceSupported } from '@/hooks/use-voice';
import { useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { router } from '@inertiajs/react';
import {
    CalendarDays,
    Camera,
    Car,
    Check,
    ChevronRight,
    ClipboardPaste,
    FolderKanban,
    ImagePlus,
    Keyboard,
    ListChecks,
    ListPlus,
    Loader2,
    Mic,
    Pencil,
    Plus,
    ShoppingCart,
    Sparkles,
    Store,
    Trash2,
    Wallet,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

// "Just say it" (design XMkKm H2 / H2a / H2b / H2c): one box that reads whatever you type, paste, photograph or say. Each pause
// sends it to /add/read (AddReader: the reader worker, with where you are and what you usually add as context), and
// the cards update. Nothing is saved until "Add". A card the reader isn't sure
// about asks first ("Add this as a new vehicle?", "Who is this for?"); every value stays editable.

type Field = { value: unknown; confidence: number };
type Item = {
    // Unique per card: a kind can come back several times (a photographed list of four to-dos is four cards).
    id: string;
    kind: string;
    module: string;
    // Where it will land, e.g. the to-do list "Household" (null: the module itself, e.g. the calendar).
    target: string | null;
    p: number;
    sure: boolean;
    fields: Record<string, Field>;
    ask: Record<string, string[]>;
    missing: string[];
    types: Record<string, string>;
    // Read but not shown as a field (the list it goes into: that's the destination in the header).
    hidden?: string[];
    quantity?: string[]; // list fields whose rows have an amount (shopping items)
    people?: string[]; // fields naming a family member ("who"): faces in Add details
    // The kind's "Add details" fields (AddKinds 'details'): never read, only filled by hand in the details form.
    details?: Record<string, Detail>;
};
// badges: a slot whose add-ons mark the card's header (task.row.badges: the Important star), given onPatch to toggle.
export type Detail = { type: string; choices?: { value: string; label: string }[]; slot?: string; badges?: string };
export type Member = { id: number; name: string };
// What the person changed on a card, kept across re-reads while they keep typing: keyed by card id. `slots` holds what
// add-ons' controls patched in Add details ({ important: true }), per slot field.
type Edit = { on?: boolean; values: Record<string, string>; lists?: Record<string, Entry[]>; slots?: Record<string, Record<string, unknown>> };
// What the Add sheet shows in its header while a card's details are open (ResponsiveModal onBack).
export type SubView = { back: () => void; label: string; aside: string };

const KIND_ICON: Record<string, LucideIcon> = {
    event: CalendarDays,
    todo: ListChecks,
    expense: Wallet,
    shopping_item: ShoppingCart,
    vehicle: Car,
    task_list: ListPlus,
    shopping_list: ListPlus,
    project: FolderKanban,
    project_task: ListChecks,
    event_task: ListChecks,
    shopping_shop: Store,
};
// How long a pause in typing before reading. 450ms (was 700): the cards show sooner, and a read still in flight is
// aborted when the person keeps typing, so the shorter pause doesn't pile up calls.
const DEBOUNCE_MS = 450;
const MAX_IMAGES = 4;
// Photos are shrunk in the browser before upload: a phone photo is 3-8 MB, the reader caps an image at 4 MiB and
// reads ~1600px as well as the original.
const MAX_SIDE = 1600;

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
const postJson = (url: string, body: unknown, signal?: AbortSignal) =>
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
        signal,
    });

function downscale(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const k = Math.min(1, MAX_SIDE / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * k);
            canvas.height = Math.round(img.height * k);
            canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(img.src);
            resolve(canvas.toDataURL('image/jpeg', 0.82));
        };
        img.onerror = reject;
        img.src = URL.createObjectURL(file);
    });
}

// One thing in a list field: shopping items come as { name, quantity } ("six pack" of "Schweppes"), plain strings too.
type Entry = { name: string; quantity: string };
const asEntries = (v: unknown): Entry[] =>
    (Array.isArray(v) ? v : []).map((x) =>
        typeof x === 'object' && x
            ? { name: String((x as Entry).name ?? ''), quantity: String((x as Entry).quantity ?? '') }
            : { name: String(x), quantity: '' },
    );

// The reader's value as the text an input shows: lists as "a, b", datetimes as the datetime-local format.
function asText(v: unknown, type: string): string {
    if (v === null || v === undefined) return '';
    if (Array.isArray(v))
        return asEntries(v)
            .map((e) => e.name)
            .join(', ');
    if (type === 'datetime') return String(v).slice(0, 16);
    return String(v);
}

export function JustSayIt({
    onClose,
    onClassic,
    pointsLeft: initialPoints,
    onSubView,
}: {
    onClose: () => void;
    onClassic: () => void;
    pointsLeft: number | null;
    onSubView?: (sub: SubView | null) => void;
}) {
    const t = useT();
    const [text, setText] = useState('');
    const [images, setImages] = useState<string[]>([]);
    const [items, setItems] = useState<Item[]>([]);
    const [members, setMembers] = useState<Member[]>([]);
    // The card whose "Add details" form is open (design H2f), by id; null = the cards.
    const [detailsFor, setDetailsFor] = useState<string | null>(null);
    const [edits, setEdits] = useState<Record<string, Edit>>({});
    const [state, setState] = useState<'idle' | 'reading' | 'saving' | 'out' | 'error'>('idle');
    const [error, setError] = useState<string | null>(null);
    const [points, setPoints] = useState(initialPoints);
    const camera = useRef<HTMLInputElement>(null);
    const gallery = useRef<HTMLInputElement>(null);
    const box = useRef<HTMLTextAreaElement>(null);

    // Voice (H2c): the words fill the box as the person talks (after anything they had typed), and the cards follow
    // through the same reading as typing. ✓ only stops listening: the cards stay on screen to be checked and added with
    // "Add", like typed input. (It used to add everything on ✓, which saved guesses nobody had looked at.)
    const typed = useRef('');
    const voice = useVoice((words) => setText(typed.current ? `${typed.current} ${words}` : words));
    // The box grows with its text (typed, pasted or spoken) so all of it shows; the sheet body scrolls, not the box.
    // Layout effect: measured before paint, so there's no one-frame jump. Reset to auto first so it shrinks too.
    useLayoutEffect(() => {
        const el = box.current;
        if (el) {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        }
    }, [text, voice.listening]); // listening hides the box; it comes back unsized
    const readFor = useRef<string | null>(null); // the text the cards on screen were read from
    const startVoice = () => {
        typed.current = text.trim();
        void voice.start();
    };

    // Read on every pause. The newest read wins: an older one still in flight is aborted.
    useEffect(() => {
        if (text.trim().length < 3 && images.length === 0) {
            setItems([]);
            return;
        }
        const ctrl = new AbortController();
        const timer = window.setTimeout(async () => {
            setState('reading');
            try {
                const res = await postJson('/add/read', { text, images, path: window.location.pathname }, ctrl.signal);
                if (res.status === 409) return setState('out');
                if (!res.ok) throw new Error(String(res.status));
                const data = await res.json();
                readFor.current = text;
                setItems(data.items);
                setMembers(data.members ?? []);
                setPoints(data.pointsLeft);
                setState('idle');
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setState('error');
            }
        }, DEBOUNCE_MS);
        return () => {
            window.clearTimeout(timer);
            ctrl.abort();
        };
    }, [text, images]);

    // The clipboard button (design H2 shows the clipboard offered in the box). A web app can only read the clipboard
    // when the person taps something, so there's no "copied 2 min ago" preview: the tap reads it (iOS shows its own
    // "Paste" confirmation, Chrome asks once). Images become photos (a copied screenshot), text joins the box.
    const [pasteNote, setPasteNote] = useState<string | null>(null);
    const canPaste = typeof navigator !== 'undefined' && !!(navigator.clipboard?.read || navigator.clipboard?.readText);
    const pasteFromClipboard = async () => {
        setPasteNote(null);
        const join = (pasted: string) => setText((cur) => (cur.trim() ? `${cur.trim()}\n${pasted.trim()}` : pasted.trim()));
        try {
            let pasted = '';
            const files: File[] = [];
            if (navigator.clipboard.read) {
                for (const clip of await navigator.clipboard.read()) {
                    const image = clip.types.find((type) => type.startsWith('image/'));
                    if (image) files.push(new File([await clip.getType(image)], 'pasted', { type: image }));
                    else if (clip.types.includes('text/plain')) pasted += await (await clip.getType('text/plain')).text();
                }
            } else {
                pasted = await navigator.clipboard.readText();
            }
            if (files.length) await addFiles(files);
            if (pasted.trim()) join(pasted);
            if (!files.length && !pasted.trim()) setPasteNote(t('add.clipboard_empty'));
        } catch {
            setPasteNote(t('add.clipboard_denied'));
        }
    };

    const addFiles = async (files: FileList | File[] | null) => {
        const list = Array.from(files ?? []).filter((f) => f.type.startsWith('image/'));
        const urls = await Promise.all(list.slice(0, MAX_IMAGES - images.length).map(downscale));
        setImages((cur) => [...cur, ...urls].slice(0, MAX_IMAGES));
    };

    const edit = (kind: string) => edits[kind] ?? { values: {} };
    // A list field's rows: the person's edits, else what the reader found.
    const entriesOf = (it: Item, name: string): Entry[] => edit(it.id).lists?.[name] ?? asEntries(it.fields[name]?.value);
    const setEntries = (kind: string, name: string, rows: Entry[]) =>
        setEdits((cur) => ({ ...cur, [kind]: { ...(cur[kind] ?? { values: {} }), lists: { ...(cur[kind]?.lists ?? {}), [name]: rows } } }));
    // A field the reader asks about ("who is this for?") starts empty: its guess is exactly what it wasn't sure of.
    const valueOf = (it: Item, name: string) =>
        it.types[name] === 'list'
            ? entriesOf(it, name)
                  .map((e) => e.name.trim())
                  .filter(Boolean)
                  .join(', ')
            : (edit(it.id).values[name] ?? (it.ask[name] ? '' : asText(it.fields[name]?.value, it.types[name])));
    const setValue = (kind: string, name: string, v: string) =>
        setEdits((cur) => ({ ...cur, [kind]: { ...(cur[kind] ?? { values: {} }), values: { ...(cur[kind]?.values ?? {}), [name]: v } } }));
    const setOn = (kind: string, on: boolean) => setEdits((cur) => ({ ...cur, [kind]: { ...(cur[kind] ?? { values: {} }), on } }));
    const slotOf = (it: Item, name: string) => edit(it.id).slots?.[name] ?? {};
    const patchSlot = (id: string, name: string, patch: Record<string, unknown>) =>
        setEdits((cur) => {
            const e = cur[id] ?? { values: {} };
            return { ...cur, [id]: { ...e, slots: { ...e.slots, [name]: { ...e.slots?.[name], ...patch } } } };
        });
    // A card starts ticked only when the reader is sure and nothing it needs is missing; unsure ones ask first.
    const needs = (it: Item) => it.missing.filter((n) => !valueOf(it, n).trim());
    const isOn = (it: Item) => edit(it.id).on ?? (it.sure && needs(it).length === 0);
    const chosen = items.filter(isOn);
    const blocked = chosen.some((it) => needs(it).length > 0);

    const save = async () => {
        setState('saving');
        setError(null);
        const payload = chosen.map((it) => ({
            kind: it.kind,
            values: Object.fromEntries(
                [...Object.keys(it.types), ...Object.keys(it.details ?? {})]
                    // A slot field is what the add-ons patched in ({ important: true }); sent whole, as Classic sends it.
                    .map((name) =>
                        it.details?.[name]?.type === 'slot'
                            ? ([name, Object.keys(slotOf(it, name)).length ? slotOf(it, name) : ''] as const)
                            : ([name, valueOf(it, name).trim()] as const),
                    )
                    .filter(([, v]) => v !== '')
                    .map(([name, v]) => [
                        name,
                        typeof v !== 'string'
                            ? v
                            : it.types[name] === 'list'
                              ? entriesOf(it, name)
                                    .filter((e) => e.name.trim())
                                    .map((e) => ({ name: e.name.trim(), quantity: e.quantity.trim() || null }))
                              : v,
                    ]),
            ),
        }));
        const res = await postJson('/add/save', { items: payload, path: window.location.pathname }).catch(() => null);
        if (res?.status === 409) return setState('out');
        if (!res?.ok) {
            const body = res ? await res.json().catch(() => null) : null;
            setError((body?.message as string) ?? t('add.unavailable'));
            return setState('idle');
        }
        onClose();
        router.reload(); // the page behind shows the new things
    };

    useEffect(() => {
        if (voice.error === 'out') setState('out');
    }, [voice.error]);

    const fieldLabel = (name: string, it?: Item) => t(it?.details?.[name] ? `add_details.f_${name}` : `add.f_${name}`);
    // Where the card lands: a list picked in Add details, else what the server named (the read or default list).
    const targetOf = (it: Item) => {
        const picked = valueOf(it, 'list_id');
        return (picked && it.details?.list_id?.choices?.find((c) => c.value === picked)?.label) || it.target || t(`nav.${it.module}`);
    };
    // What Add details offers beyond the card, for the grey hint after "Add details" ("Who, Note, List"): the fields the
    // card isn't showing, then the details-only ones. An add-on slot can't be named here (it may render nothing).
    const extras = (it: Item) => [
        ...Object.keys(it.types).filter((n) => !shown(it, n) && !it.hidden?.includes(n) && !it.ask[n]),
        ...Object.keys(it.details ?? {}).filter((n) => it.details![n].type !== 'slot' && !valueOf(it, n)),
    ];
    // The card's headline is its first field (an event's title, what an expense was for, the list's name), else
    // "New to-do" while that's still empty: never some other field (it once showed the person, "Bogdan").
    const headline = (it: Item) => valueOf(it, Object.keys(it.types)[0]).trim() || t('add.new_kind', { kind: t(`add.kind_${it.kind}`) });
    const inputType = (type: string) => (type === 'datetime' ? 'datetime-local' : type === 'date' ? 'date' : type === 'number' ? 'number' : 'text');
    // A date-time at midnight is a whole day (a holiday, "this week"): the card shows just the date, not "at 00:00".
    // The server reads a bare date as all-day too (CalendarServiceProvider: midnight = no time).
    const wholeDay = (it: Item, name: string) => {
        const v = valueOf(it, name);
        return it.types[name] === 'datetime' && v !== '' && (!v.includes('T') || v.endsWith('T00:00'));
    };

    // A card shows the fields the reader found (or still needs, or the person typed in); the rest wait in Add details.
    const shown = (it: Item, name: string) =>
        !it.ask[name] && !it.hidden?.includes(name) && !!(it.fields[name] || it.missing.includes(name) || edit(it.id).values[name] !== undefined);
    // One editable field, the same input on the card and in its Add details form.
    const fieldRow = (it: Item, name: string) => {
        const unsure = (it.fields[name]?.confidence ?? 1) < 0.5;
        const empty = it.missing.includes(name) && !valueOf(it, name).trim();
        // A read field's type, else its Add details one (a shopping item's due date, a to-do's note).
        const type = it.types[name] ?? it.details?.[name]?.type ?? 'text';
        const label = fieldLabel(name, it);
        const control = `text-primary min-w-0 rounded-lg border px-2.5 py-1.5 text-base ${empty ? 'border-destructive' : unsure ? 'bg-accent-subtle' : 'bg-surface-card'}`;
        // Back from Add details (design H2g), "who" and picked choices are rows too: a compact select, in the same
        // label-left / control-right column as the other fields (the form-row rule), not the details form's faces.
        const select = (options: { value: string; label: string }[], current: string) => (
            <label key={name} className="grid grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-2">
                <span className="text-muted-foreground font-mono text-[11px] uppercase">{label}</span>
                <select value={current} onChange={(e) => setValue(it.id, name, e.target.value)} className={`${control} h-[38px]`}>
                    {options.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            </label>
        );
        if (it.people?.includes(name)) {
            // The value is a name, as the reader gives it ("Marco"): matched to a member by full or first name, else
            // kept as its own option so a name the family list doesn't have isn't silently dropped.
            const v = valueOf(it, name).trim();
            const picked = members.find((m) => [m.name.toLowerCase(), m.name.split(' ')[0].toLowerCase()].includes(v.toLowerCase()));
            return select(
                [
                    { value: '', label: t('add_details.anyone') },
                    ...members.map((m) => ({ value: m.name, label: m.name })),
                    ...(v && !picked ? [{ value: v, label: v }] : []),
                ],
                picked?.name ?? v,
            );
        }
        if (type === 'choice') return select(it.details?.[name]?.choices ?? [], valueOf(it, name));
        if (type === 'list') {
            // One row per thing: its name and, for shopping, how much ("Schweppes" × "six pack").
            const rows = entriesOf(it, name);
            const qty = it.quantity?.includes(name);
            const put = (i: number, patch: Partial<Entry>) =>
                setEntries(
                    it.id,
                    name,
                    rows.map((r, j) => (j === i ? { ...r, ...patch } : r)),
                );
            return (
                <div key={name} className="flex flex-col gap-1.5">
                    <span className="text-muted-foreground font-mono text-[11px] uppercase">{label}</span>
                    {rows.map((r, i) => (
                        <div
                            key={i}
                            className={`grid ${qty ? 'grid-cols-[minmax(0,1fr)_6.5rem_auto]' : 'grid-cols-[minmax(0,1fr)_auto]'} items-center gap-1.5`}
                        >
                            <input
                                value={r.name}
                                onChange={(e) => put(i, { name: e.target.value })}
                                aria-label={label}
                                className="text-primary bg-surface-card min-w-0 rounded-lg border px-2.5 py-1.5 text-base"
                            />
                            {qty && (
                                <input
                                    value={r.quantity}
                                    onChange={(e) => put(i, { quantity: e.target.value })}
                                    placeholder={t('add.f_quantity')}
                                    aria-label={t('add.f_quantity')}
                                    className="text-primary bg-surface-card min-w-0 rounded-lg border px-2.5 py-1.5 text-base"
                                />
                            )}
                            <button
                                type="button"
                                onClick={() =>
                                    setEntries(
                                        it.id,
                                        name,
                                        rows.filter((_, j) => j !== i),
                                    )
                                }
                                aria-label={t('add.remove_item')}
                                className="text-muted-foreground flex size-9 items-center justify-center rounded-full"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                    ))}
                    {empty && <span className="text-destructive text-sm">{t('add.needed')}</span>}
                </div>
            );
        }
        return (
            <label key={name} className="grid grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-2">
                <span className="text-muted-foreground font-mono text-[11px] uppercase">{label}</span>
                <input
                    type={wholeDay(it, name) ? 'date' : inputType(type)}
                    value={wholeDay(it, name) ? valueOf(it, name).slice(0, 10) : valueOf(it, name)}
                    onChange={(e) => setValue(it.id, name, e.target.value)}
                    placeholder={empty ? t('add.needed') : ''}
                    className={control}
                />
            </label>
        );
    };

    // Add details fields given a value (design H2g): back on the card they're rows like the read ones. Slots aren't
    // rows (their add-ons draw nothing generic); the Important one shows as the header's star instead (badges).
    const detailRows = (it: Item) => Object.keys(it.details ?? {}).filter((n) => it.details![n].type !== 'slot' && valueOf(it, n).trim() !== '');
    // Anything set in Add details (a detail field, or an add-on's control): the row turns into "Edit details".
    const touched = (it: Item) =>
        Object.keys(it.details ?? {}).some((n) => (it.details![n].type === 'slot' ? Object.keys(slotOf(it, n)).length > 0 : !!valueOf(it, n).trim()));

    // The card whose details are open (gone if a late read replaced the cards: back to the list then).
    const detailed = items.find((it) => it.id === detailsFor) ?? null;
    const detailedIndex = detailed ? items.indexOf(detailed) : -1;
    // Strings, not t, in the deps: useT returns a new function every render, which would loop through the parent.
    const subLabel = t('add_details.things', { count: items.length });
    const subAside = detailed ? t('add_details.position', { kind: t(`add.kind_${detailed.kind}`), i: detailedIndex + 1, n: items.length }) : '';
    useEffect(() => {
        onSubView?.(detailed ? { back: () => setDetailsFor(null), label: subLabel, aside: subAside } : null);
    }, [detailed, subLabel, subAside, onSubView]);

    if (detailed) {
        return (
            <AddDetails
                item={detailed}
                members={members}
                headlineName={Object.keys(detailed.types)[0]}
                value={(name) => valueOf(detailed, name)}
                setValue={(name, v) => setValue(detailed.id, name, v)}
                slot={(name) => slotOf(detailed, name)}
                patchSlot={(name, patch) => patchSlot(detailed.id, name, patch)}
                label={(name) => fieldLabel(name, detailed)}
                field={(name) => fieldRow(detailed, name)}
                onDone={() => setDetailsFor(null)}
                onLeaveOut={() => {
                    setOn(detailed.id, false);
                    setDetailsFor(null);
                }}
            />
        );
    }

    if (state === 'out') {
        return (
            <div className="flex flex-col gap-4">
                <p className="bg-accent-subtle text-primary rounded-2xl p-4 text-sm">{t('add.out_of_points')}</p>
                <button type="button" onClick={onClassic} className="bg-primary text-primary-foreground rounded-full py-3 font-semibold">
                    {t('add.classic')}
                </button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {/* The box: text, then any photos, then the photo buttons. Paste works for text and images. */}
            <div className="border-primary bg-surface-card flex flex-col gap-3 rounded-2xl border-2 p-3">
                {voice.listening ? (
                    <div className="flex flex-col gap-3" aria-live="polite">
                        <span className="text-muted-foreground flex items-center gap-2 font-mono text-[11px] font-semibold tracking-[0.14em] uppercase">
                            <span className="bg-destructive size-2 animate-pulse rounded-full" aria-hidden />
                            {t('add.listening')} · {Math.floor(voice.seconds / 60)}:{String(voice.seconds % 60).padStart(2, '0')}
                        </span>
                        <p className="text-primary min-h-14 text-lg leading-snug font-semibold">{text ? `“${text}”` : t('add.listening_hint')}</p>
                        {/* The bars run the full width of the box (edge to edge), however wide the sheet is. */}
                        <div className="flex h-8 items-center justify-between gap-[3px]" aria-hidden>
                            {voice.levels.map((l, i) => (
                                <span
                                    key={i}
                                    className="bg-primary w-1.5 rounded-full transition-[height]"
                                    style={{ height: `${Math.max(12, l * 100)}%`, opacity: l > 0.02 ? 1 : 0.25 }}
                                />
                            ))}
                        </div>
                    </div>
                ) : (
                    <textarea
                        ref={box}
                        autoFocus
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        onPaste={(e) => {
                            if (e.clipboardData.files.length) {
                                e.preventDefault();
                                void addFiles(e.clipboardData.files);
                            }
                        }}
                        rows={2}
                        placeholder={t('add.placeholder')}
                        aria-label={t('add.say_it')}
                        // text-base = 16px: smaller zooms the page on iOS when the field is focused.
                        className="text-primary placeholder:text-muted-foreground min-h-14 w-full resize-none bg-transparent text-base font-semibold outline-none"
                    />
                )}
                {images.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {images.map((src, i) => (
                            <span key={i} className="relative">
                                <img src={src} alt="" className="size-16 rounded-lg border object-cover" />
                                <button
                                    type="button"
                                    onClick={() => setImages((cur) => cur.filter((_, j) => j !== i))}
                                    aria-label={t('add.remove_image')}
                                    className="bg-primary text-primary-foreground absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full"
                                >
                                    <X className="size-3.5" />
                                </button>
                            </span>
                        ))}
                    </div>
                )}
                {!voice.listening && (
                    <div className="flex items-center gap-2 border-t pt-3">
                        <button
                            type="button"
                            onClick={() => camera.current?.click()}
                            aria-label={t('add.camera')}
                            disabled={images.length >= MAX_IMAGES}
                            className="bg-surface text-primary flex size-11 items-center justify-center rounded-full disabled:opacity-40"
                        >
                            <Camera className="size-5" />
                        </button>
                        <button
                            type="button"
                            onClick={() => gallery.current?.click()}
                            aria-label={t('add.gallery')}
                            disabled={images.length >= MAX_IMAGES}
                            className="bg-surface text-primary flex size-11 items-center justify-center rounded-full disabled:opacity-40"
                        >
                            <ImagePlus className="size-5" />
                        </button>
                        {canPaste && (
                            <button
                                type="button"
                                onClick={() => void pasteFromClipboard()}
                                aria-label={t('add.paste')}
                                className="bg-surface text-primary flex size-11 items-center justify-center rounded-full"
                            >
                                <ClipboardPaste className="size-5" />
                            </button>
                        )}
                        <input
                            ref={camera}
                            type="file"
                            accept="image/*"
                            capture="environment"
                            hidden
                            onChange={(e) => void addFiles(e.target.files)}
                        />
                        <input ref={gallery} type="file" accept="image/*" multiple hidden onChange={(e) => void addFiles(e.target.files)} />
                        <span className="text-muted-foreground ml-auto flex items-center gap-1.5 text-sm" aria-live="polite">
                            {(state === 'reading' || voice.transcribing) && (
                                <>
                                    <Loader2 className="size-4 animate-spin" />
                                    {t('add.reading')}
                                </>
                            )}
                        </span>
                        {voiceSupported() && (
                            <button
                                type="button"
                                onClick={startVoice}
                                aria-label={t('add.mic')}
                                className="bg-primary text-accent-strong flex size-11 items-center justify-center rounded-full"
                            >
                                <Mic className="size-5" />
                            </button>
                        )}
                    </div>
                )}
            </div>

            {(state === 'error' || voice.error === 'unavailable') && <p className="text-destructive text-sm">{t('add.unavailable')}</p>}
            {voice.error === 'denied' && <p className="text-destructive text-sm">{t('add.mic_denied')}</p>}
            {pasteNote && <p className="text-muted-foreground text-sm">{pasteNote}</p>}
            {voice.listening && items.length > 0 && (
                <h3 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.14em] uppercase">
                    {t('add.filing')}
                    <span className="text-primary ml-2">{chosen.length}</span>
                </h3>
            )}
            {state === 'idle' && items.length === 0 && (text.trim().length >= 3 || images.length > 0) && (
                <p className="text-muted-foreground text-sm">{t('add.nothing')}</p>
            )}

            {/* One card per thing found, most likely first. */}
            {items.map((it) => {
                const Icon = KIND_ICON[it.kind] ?? Sparkles;
                const on = isOn(it);
                const asking = !it.sure && edit(it.id).on === undefined;
                return (
                    <section key={it.id} className={`rounded-2xl border p-3 ${on ? 'bg-surface-card' : 'bg-surface'}`}>
                        <div className="flex items-center gap-3">
                            <span className="bg-accent-strong text-accent-strong-foreground flex size-10 shrink-0 items-center justify-center rounded-xl">
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="text-muted-foreground block font-mono text-[11px] font-semibold tracking-[0.14em] uppercase">
                                    {t(`add.kind_${it.kind}`)} → {targetOf(it)}
                                </span>
                                <span className="text-primary block truncate font-semibold first-letter:uppercase">{headline(it)}</span>
                            </span>
                            {/* The add-ons' marks for this card (design H2g: the Important star), drawn by the add-ons
                                themselves through the primitive's row-badge slot; onPatch lets a tap toggle it, kept on
                                the card like a change made in Add details. */}
                            {Object.entries(it.details ?? {}).map(
                                ([n, d]) =>
                                    d.badges && (
                                        <Slot
                                            key={n}
                                            name={d.badges}
                                            ctx={{ task: slotOf(it, n), onPatch: (p: Record<string, unknown>) => patchSlot(it.id, n, p) }}
                                        />
                                    ),
                            )}
                            {!asking && (
                                <button
                                    type="button"
                                    role="checkbox"
                                    aria-checked={on}
                                    aria-label={t(`add.kind_${it.kind}`)}
                                    onClick={() => setOn(it.id, !on)}
                                    className={`flex size-8 shrink-0 items-center justify-center rounded-full border-2 ${on ? 'bg-primary border-primary text-primary-foreground' : 'border-primary'}`}
                                >
                                    {on && <Check className="size-4" />}
                                </button>
                            )}
                        </div>

                        {/* Not sure what it is: ask, don't assume. */}
                        {asking && (
                            <div className="bg-accent-subtle mt-3 flex flex-wrap items-center gap-2 rounded-xl p-2.5">
                                <span className="text-primary min-w-0 flex-1 text-sm font-medium">{t(`add.ask_${it.kind}`)}</span>
                                <button
                                    type="button"
                                    onClick={() => setOn(it.id, true)}
                                    className="bg-primary text-primary-foreground rounded-full px-4 py-1.5 text-sm font-semibold"
                                >
                                    {t('add.yes')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setOn(it.id, false)}
                                    className="bg-surface-card text-primary rounded-full px-4 py-1.5 text-sm font-semibold"
                                >
                                    {t('add.no')}
                                </button>
                            </div>
                        )}

                        {on && (
                            <div className="mt-3 flex flex-col gap-2 border-t pt-3">
                                {Object.keys(it.types)
                                    .filter((name) => shown(it, name))
                                    .map((name) => fieldRow(it, name))}
                                {detailRows(it).map((name) => fieldRow(it, name))}
                                {/* "Which kid is this for?": the reader wasn't sure, so it asks. */}
                                {Object.entries(it.ask).map(([name, options]) => (
                                    <div key={name} className="bg-accent-subtle flex flex-wrap items-center gap-2 rounded-xl p-2.5">
                                        <span className="text-primary w-full text-sm font-medium">{t('add.which')}</span>
                                        {options.map((o) => (
                                            <button
                                                key={o}
                                                type="button"
                                                onClick={() => setValue(it.id, name, o)}
                                                className={`rounded-full px-3 py-1.5 text-sm font-semibold ${valueOf(it, name) === o ? 'bg-primary text-primary-foreground' : 'bg-surface-card text-primary'}`}
                                            >
                                                {o}
                                            </button>
                                        ))}
                                    </div>
                                ))}
                                {/* Design H2e: everything the box didn't catch, in this card's own form (H2f). Once something
                                    was set there it's "Edit details" (H2g), the hint naming what's still empty (none: no
                                    hint). Hidden when the card shows all there is and nothing was set. */}
                                {(extras(it).length > 0 || touched(it)) && (
                                    <button
                                        type="button"
                                        onClick={() => setDetailsFor(it.id)}
                                        className="text-primary flex min-h-11 items-center gap-2 border-t pt-2 text-left"
                                    >
                                        {touched(it) ? <Pencil className="size-5 shrink-0" /> : <Plus className="size-5 shrink-0" />}
                                        <span className="shrink-0 font-semibold">
                                            {t(touched(it) ? 'add_details.edit_details' : 'add_details.add_details')}
                                        </span>
                                        <span className="text-muted-foreground min-w-0 flex-1 truncate">
                                            {extras(it)
                                                .slice(0, 3)
                                                .map((n) => fieldLabel(n, it))
                                                .join(', ')}
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                                    </button>
                                )}
                            </div>
                        )}
                    </section>
                );
            })}

            {error && <p className="text-destructive text-sm">{error}</p>}

            {voice.listening || voice.transcribing ? (
                // While talking (design H2c): type instead (keeps the words), done (review the cards), or throw it away.
                <div className="flex flex-col items-center gap-2">
                    <div className="flex items-center justify-center gap-6">
                        <button
                            type="button"
                            onClick={async () => {
                                await voice.stop();
                                box.current?.focus();
                            }}
                            aria-label={t('add.type_instead')}
                            className="bg-surface text-primary flex size-12 items-center justify-center rounded-full"
                        >
                            <Keyboard className="size-5" />
                        </button>
                        <button
                            type="button"
                            onClick={() => void voice.stop()}
                            disabled={!voice.listening}
                            aria-label={t('common.done')}
                            className="bg-primary text-accent-strong ring-accent-strong flex size-20 items-center justify-center rounded-full ring-4 disabled:opacity-60"
                        >
                            {!voice.listening ? <Loader2 className="size-7 animate-spin" /> : <Check className="size-8" />}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                voice.cancel();
                                setText(typed.current);
                            }}
                            aria-label={t('add.discard')}
                            className="bg-surface text-primary flex size-12 items-center justify-center rounded-full"
                        >
                            <Trash2 className="size-5" />
                        </button>
                    </div>
                    <p className="text-muted-foreground text-center text-xs">{t('add.voice_hint')}</p>
                </div>
            ) : (
                items.length > 0 && (
                    <button
                        type="button"
                        onClick={() => void save()}
                        disabled={chosen.length === 0 || blocked || state === 'saving'}
                        className="bg-accent-strong text-accent-strong-foreground border-primary flex items-center justify-center gap-2 rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-50"
                    >
                        {state === 'saving' && <Loader2 className="size-5 animate-spin" />}
                        {t('add.add_n', { count: chosen.length })}
                    </button>
                )
            )}

            {!voice.listening && (
                <p className="text-muted-foreground text-center text-xs">
                    {t('add.hint')}
                    {points !== null && <> {t('add.points_left', { count: points })}</>}
                </p>
            )}
        </div>
    );
}
