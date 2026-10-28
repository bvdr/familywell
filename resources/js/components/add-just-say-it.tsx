import { useVoice, voiceSupported } from '@/hooks/use-voice';
import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import {
    CalendarDays,
    Camera,
    Car,
    Check,
    ImagePlus,
    Keyboard,
    ListChecks,
    ListPlus,
    Loader2,
    Mic,
    ShoppingCart,
    Sparkles,
    Trash2,
    Wallet,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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
};
// What the person changed on a card, kept across re-reads while they keep typing: keyed by kind.
type Edit = { on?: boolean; values: Record<string, string>; lists?: Record<string, Entry[]> };

const KIND_ICON: Record<string, LucideIcon> = {
    event: CalendarDays,
    todo: ListChecks,
    expense: Wallet,
    shopping_item: ShoppingCart,
    vehicle: Car,
    task_list: ListPlus,
    shopping_list: ListPlus,
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
}: {
    onClose: () => void;
    onClassic: () => void;
    pointsLeft: number | null;
}) {
    const t = useT();
    const [text, setText] = useState('');
    const [images, setImages] = useState<string[]>([]);
    const [items, setItems] = useState<Item[]>([]);
    const [edits, setEdits] = useState<Record<string, Edit>>({});
    const [state, setState] = useState<'idle' | 'reading' | 'saving' | 'out' | 'error'>('idle');
    const [error, setError] = useState<string | null>(null);
    const [points, setPoints] = useState(initialPoints);
    const camera = useRef<HTMLInputElement>(null);
    const gallery = useRef<HTMLInputElement>(null);
    const box = useRef<HTMLTextAreaElement>(null);

    // Voice (H2c): the words fill the box as the person talks (after anything they had typed), and the cards follow
    // through the same reading as typing. ✓ stops, waits for the last words and their cards, then adds everything.
    const typed = useRef('');
    const voice = useVoice((words) => setText(typed.current ? `${typed.current} ${words}` : words));
    const [addWhenReady, setAddWhenReady] = useState(false);
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
                Object.keys(it.types)
                    .map((name) => [name, valueOf(it, name).trim()] as const)
                    .filter(([, v]) => v !== '')
                    .map(([name, v]) => [
                        name,
                        it.types[name] === 'list'
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

    // After ✓: once the final words are read (no recording, no transcription, the cards are for this text), add.
    useEffect(() => {
        if (!addWhenReady || voice.listening || voice.transcribing || state !== 'idle' || readFor.current !== text) return;
        setAddWhenReady(false);
        if (chosen.length && !blocked) void save();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [addWhenReady, voice.listening, voice.transcribing, state, text]);
    useEffect(() => {
        if (voice.error === 'out') setState('out');
        if (voice.error) setAddWhenReady(false); // a failed transcription must not leave ✓ spinning
    }, [voice.error]);

    const fieldLabel = (name: string) => t(`add.f_${name}`);
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
                        <div className="flex h-8 items-center gap-[3px]" aria-hidden>
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
                                    {t(`add.kind_${it.kind}`)} → {it.target ?? t(`nav.${it.module}`)}
                                </span>
                                <span className="text-primary block truncate font-semibold first-letter:uppercase">{headline(it)}</span>
                            </span>
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
                                    .filter(
                                        (name) =>
                                            !it.ask[name] &&
                                            !it.hidden?.includes(name) &&
                                            (it.fields[name] || it.missing.includes(name) || edit(it.id).values[name] !== undefined),
                                    )
                                    .map((name) => {
                                        const unsure = (it.fields[name]?.confidence ?? 1) < 0.5;
                                        const empty = it.missing.includes(name) && !valueOf(it, name).trim();
                                        if (it.types[name] === 'list') {
                                            // One row per thing: its name and how much ("Schweppes" × "six pack").
                                            const rows = entriesOf(it, name);
                                            const put = (i: number, patch: Partial<Entry>) =>
                                                setEntries(
                                                    it.id,
                                                    name,
                                                    rows.map((r, j) => (j === i ? { ...r, ...patch } : r)),
                                                );
                                            return (
                                                <div key={name} className="flex flex-col gap-1.5">
                                                    <span className="text-muted-foreground font-mono text-[11px] uppercase">{fieldLabel(name)}</span>
                                                    {rows.map((r, i) => (
                                                        <div key={i} className="grid grid-cols-[minmax(0,1fr)_6.5rem_auto] items-center gap-1.5">
                                                            <input
                                                                value={r.name}
                                                                onChange={(e) => put(i, { name: e.target.value })}
                                                                aria-label={t('add.f_items')}
                                                                className="text-primary bg-surface-card min-w-0 rounded-lg border px-2.5 py-1.5 text-base"
                                                            />
                                                            <input
                                                                value={r.quantity}
                                                                onChange={(e) => put(i, { quantity: e.target.value })}
                                                                placeholder={t('add.f_quantity')}
                                                                aria-label={t('add.f_quantity')}
                                                                className="text-primary bg-surface-card min-w-0 rounded-lg border px-2.5 py-1.5 text-base"
                                                            />
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
                                                <span className="text-muted-foreground font-mono text-[11px] uppercase">{fieldLabel(name)}</span>
                                                <input
                                                    type={wholeDay(it, name) ? 'date' : inputType(it.types[name])}
                                                    value={wholeDay(it, name) ? valueOf(it, name).slice(0, 10) : valueOf(it, name)}
                                                    onChange={(e) => setValue(it.id, name, e.target.value)}
                                                    placeholder={empty ? t('add.needed') : ''}
                                                    className={`text-primary min-w-0 rounded-lg border px-2.5 py-1.5 text-base ${empty ? 'border-destructive' : unsure ? 'bg-accent-subtle' : 'bg-surface-card'}`}
                                                />
                                            </label>
                                        );
                                    })}
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
                            </div>
                        )}
                    </section>
                );
            })}

            {error && <p className="text-destructive text-sm">{error}</p>}

            {voice.listening || addWhenReady ? (
                // While talking (design H2c): type instead (keeps the words), add everything, or throw it away.
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
                            onClick={() => {
                                setAddWhenReady(true);
                                void voice.stop();
                            }}
                            disabled={addWhenReady}
                            aria-label={t('add.add_all')}
                            className="bg-primary text-accent-strong ring-accent-strong flex size-20 items-center justify-center rounded-full ring-4 disabled:opacity-60"
                        >
                            {addWhenReady ? <Loader2 className="size-7 animate-spin" /> : <Check className="size-8" />}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                voice.cancel();
                                setAddWhenReady(false);
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
