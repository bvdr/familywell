import { Check, ChevronRight, CornerDownRight, MoreHorizontal, Plus, Search } from 'lucide-react';
import { useLayoutEffect, useMemo, useRef, useState } from 'react';

import { type Detail } from '@/components/add-just-say-it';
import { appLocale, useT } from '@/lib/i18n';
import { ICON_CATEGORIES, iconsFor, LIST_ICON_DEFS, listIconFor, searchIcons } from '@/lib/list-icons';
import { ago, listColor, NEW_LIST_COLORS } from '@/lib/tasks';

// The list picker and New list (design j6QDy4 T3b / T3c / T3d), pushed into a sheet: Add details (a to-do's list) and
// the task sheet (move a task). Three screens: pick a list, make one, browse every icon. The sheet keeps which screen
// is showing (its header's back pill and words) and the new list's draft, so the icon browser can step back to it.

export type Choice = NonNullable<Detail['choices']>[number];
export type NewListDraft = { name: string; icon: string; color: string; visibility: 'family' | 'private'; iconPicked?: boolean };
export const emptyDraft = (name = ''): NewListDraft => ({ name, icon: iconsFor(name)[0]?.key ?? 'list', color: 'yellow', visibility: 'family' });

// A new list travels as the choice value "new:" + JSON (name, icon, colour, who sees it); made on Add / Save.
export const newListValue = (d: NewListDraft) =>
    `new:${JSON.stringify({ name: d.name.trim(), icon: d.icon, color: d.color, visibility: d.visibility })}`;
export function parseNew(value: string): NewListDraft | null {
    if (!value.startsWith('new:')) return null;
    try {
        const d = JSON.parse(value.slice(4));
        return { ...emptyDraft(d.name), ...d };
    } catch {
        return emptyDraft(value.slice(4)); // the older "new:Garage"
    }
}
// A list made in the picker isn't among the server's choices yet: add it, named, so the row and the card show it.
export const withNew = (options: Choice[], value: string): Choice[] => {
    const d = parseNew(value);
    return d && !options.some((o) => o.value === value) ? [{ value, label: d.name, icon: d.icon, color: d.color }, ...options] : options;
};

const eyebrow = 'text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase';

function Tile({ icon, color, size = 'size-10' }: { icon?: string; color?: string; size?: string }) {
    const Icon = listIconFor(icon ?? 'list');
    return (
        <span className={`flex ${size} shrink-0 items-center justify-center rounded-xl ${listColor(color ?? 'green')}`}>
            <Icon className="size-5" />
        </span>
    );
}

// T3b: search first; SUGGESTED holds the current list (ticked, tinted) and one guess from similar tasks; ALL LISTS
// the rest by last change or A–Z; New list sits last, outlined, so it's there without competing.
export function ListPicker({
    options,
    value,
    guess,
    onPick,
    onNew,
    newSub,
}: {
    options: Choice[];
    value: string;
    guess?: { value: string; word: string; count: number } | null;
    onPick: (v: string) => void;
    onNew: (name: string) => void;
    newSub: string; // "Name it, pick an icon, the task moves in"
}) {
    const t = useT();
    const [q, setQ] = useState('');
    const [az, setAz] = useState(false);
    const query = q.trim().toLowerCase();
    const current = options.find((o) => o.value === value);
    const second = guess && guess.value !== value ? options.find((o) => o.value === guess.value) : undefined;
    const rest = useMemo(
        () =>
            options
                .filter((o) => (query ? o.label.toLowerCase().includes(query) : o !== current && o !== second))
                .sort((a, b) => (az ? a.label.localeCompare(b.label, appLocale()) : (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))),
        [options, query, current, second, az],
    );
    const open = (o: Choice) => o.open !== undefined && t('add_details.n_open', { count: o.open });
    const vis = (o: Choice) => o.visibility && t(`add_details.vis_${o.visibility}`);
    const meta = (o: Choice) =>
        o === current
            ? [t('add_details.current'), open(o), vis(o)]
            : o === second && guess
              ? [t('add_details.has_like', { count: guess.count, word: guess.word }), open(o)]
              : [o.updated_at && ago(o.updated_at), o.updated_at && (o.by || t('add_details.only_you')), open(o)];
    const row = (o: Choice) => {
        const lines = meta(o).filter(Boolean);
        const on = o.value === value;
        return (
            <button
                key={o.value}
                type="button"
                onClick={() => onPick(o.value)}
                aria-pressed={on}
                className={`flex w-full items-center gap-3 border-b px-3.5 py-2.5 text-left last:border-b-0 ${on && !query ? 'bg-accent-strong/15' : ''}`}
            >
                <Tile icon={o.icon} color={o.color} />
                <span className="min-w-0 flex-1">
                    <span className="text-primary block truncate text-base font-semibold">{o.label}</span>
                    {lines.length > 0 && <span className="text-muted-foreground block truncate text-sm">{lines.join(' · ')}</span>}
                </span>
                {on && (
                    <span className="bg-primary text-primary-foreground flex size-6 shrink-0 items-center justify-center rounded-full">
                        <Check className="size-4" />
                    </span>
                )}
            </button>
        );
    };
    return (
        <div className="flex flex-col gap-4">
            <label className="bg-surface flex min-h-12 items-center gap-2.5 rounded-2xl px-4">
                <Search className="text-muted-foreground size-5 shrink-0" />
                <input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('add_details.search_lists', { count: options.length })}
                    // 16px so iOS doesn't zoom in on focus.
                    className="text-primary placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-base outline-none"
                />
            </label>
            {!query && (current || second) && (
                <section className="flex flex-col gap-2">
                    <h3 className={eyebrow}>{t('add_details.suggested')}</h3>
                    <div className="bg-surface-card overflow-hidden rounded-2xl border">
                        {current && row(current)}
                        {second && row(second)}
                    </div>
                </section>
            )}
            {rest.length > 0 && (
                <section className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className={eyebrow}>{t(query ? 'add_details.matching' : 'add_details.all_lists')}</h3>
                        <div className="bg-surface flex rounded-full p-0.5 text-sm font-semibold">
                            {[false, true].map((v) => (
                                <button
                                    key={String(v)}
                                    type="button"
                                    aria-pressed={az === v}
                                    onClick={() => setAz(v)}
                                    className={`rounded-full px-3 py-1 ${az === v ? 'bg-surface-card text-primary shadow-sm' : 'text-muted-foreground'}`}
                                >
                                    {t(v ? 'add_details.a_z' : 'add_details.recent')}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="bg-surface-card overflow-hidden rounded-2xl border">{rest.map(row)}</div>
                </section>
            )}
            {/* New list: last, outlined. What's typed in search becomes its name. */}
            <button
                type="button"
                onClick={(e) => {
                    holdKeyboard(e.currentTarget);
                    onNew(q.trim());
                }}
                className="border-primary bg-surface-card flex w-full items-center gap-3 rounded-2xl border-2 px-3.5 py-3 text-left"
            >
                <span className="bg-accent-strong text-accent-strong-foreground flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <Plus className="size-5" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="text-primary block truncate text-base font-semibold">
                        {q.trim() ? t('add_details.create_list', { name: q.trim() }) : t('add_details.new_list')}
                    </span>
                    <span className="text-muted-foreground block truncate text-sm">{newSub}</span>
                </span>
                <ChevronRight className="text-muted-foreground size-5 shrink-0" />
            </button>
        </div>
    );
}

// iOS raises the keyboard only for a focus() inside the tap itself, and the New list screen mounts after the tap.
// So the tap focuses an invisible stand-in (the keyboard comes up) and the name field takes the focus over when it
// mounts. The stand-in sits in the sheet, not <body>: the sheet's focus trap would pull focus back out of <body>.
function holdKeyboard(from: HTMLElement) {
    const stand = document.createElement('input');
    stand.setAttribute('aria-hidden', 'true');
    stand.tabIndex = -1;
    // 16px: a smaller font makes iOS zoom in on focus.
    stand.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;';
    (from.closest('[role="dialog"]') ?? document.body).appendChild(stand);
    stand.focus({ preventScroll: true });
    setTimeout(() => stand.remove(), 1000);
}

// T3c: name first (prefilled from search), the first icon row picked from the name (••• opens every icon), then the
// colour and who sees it, a line saying where the task goes, and one button.
export function NewListForm({
    draft,
    setDraft,
    note,
    submitLabel,
    onSubmit,
    onMoreIcons,
    busy,
}: {
    draft: NewListDraft;
    setDraft: (d: NewListDraft) => void;
    note: string | null; // "“Replace furnace filter” moves here from Household."
    submitLabel: string;
    onSubmit: () => void;
    onMoreIcons: () => void;
    busy?: boolean;
}) {
    const t = useT();
    const nameRef = useRef<HTMLInputElement>(null);
    // Arrive typing: the name (prefilled from search) focused and selected, so typing replaces it (user, Nov 3).
    // preventScroll: the sheet stays at its top with the field in view above the keyboard.
    useLayoutEffect(() => {
        const el = nameRef.current;
        el?.focus({ preventScroll: true });
        el?.setSelectionRange(0, el.value.length);
    }, []);
    const fits = iconsFor(draft.name);
    // Row 1: what fits the name (padded with the everyday ones); row 2: common picks, then •••.
    const everyday = ['home', 'kids', 'baby', 'dog', 'gift', 'trip', 'tools', 'cart', 'sprout', 'docs', 'car', 'heart', 'list'];
    const firstKeys = [...new Set([...(draft.iconPicked ? [draft.icon] : []), ...fits.map((d) => d.key), ...everyday])].slice(0, 7);
    const secondKeys = everyday.filter((k) => !firstKeys.includes(k)).slice(0, 6);
    const setName = (name: string) => setDraft({ ...draft, name, icon: draft.iconPicked ? draft.icon : (iconsFor(name)[0]?.key ?? draft.icon) });
    const iconBtn = (key: string) => {
        const Icon = listIconFor(key);
        const on = draft.icon === key;
        return (
            <button
                key={key}
                type="button"
                aria-pressed={on}
                aria-label={key}
                onClick={() => setDraft({ ...draft, icon: key, iconPicked: true })}
                className={`flex aspect-square items-center justify-center rounded-xl ${on ? `border-primary border-2 ${listColor(draft.color)}` : 'bg-surface text-primary'}`}
            >
                <Icon className="size-5" />
            </button>
        );
    };
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <Tile icon={draft.icon} color={draft.color} size="size-14" />
                {/* autoComplete off and no "name" in the field's name: iOS offered "AutoFill Contact" here (it reads
                    the name attribute; autoComplete alone didn't stop it). */}
                <input
                    ref={nameRef}
                    value={draft.name}
                    autoComplete="off"
                    name="list-title"
                    enterKeyHint="done"
                    onChange={(e) => setName(e.target.value)}
                    placeholder={t('tasks.list_name_placeholder')}
                    aria-label={t('tasks.list_name_placeholder')}
                    // 16px+ so iOS doesn't zoom in on focus.
                    className="border-primary text-primary placeholder:text-muted-foreground bg-surface-card min-w-0 flex-1 rounded-2xl border-2 px-4 py-3 text-xl font-bold outline-none"
                />
            </div>
            <section className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between gap-2">
                    <h3 className={eyebrow}>{t('add_details.icon')}</h3>
                    {fits.length > 0 && (
                        <span className="text-muted-foreground truncate text-sm">{t('add_details.icons_for', { name: draft.name.trim() })}</span>
                    )}
                </div>
                <div className="grid grid-cols-7 gap-2">
                    {firstKeys.map(iconBtn)}
                    {secondKeys.map(iconBtn)}
                    <button
                        type="button"
                        onClick={onMoreIcons}
                        aria-label={t('add_details.more_icons')}
                        className="border-primary text-primary bg-surface-card flex aspect-square items-center justify-center rounded-xl border-2"
                    >
                        <MoreHorizontal className="size-5" />
                    </button>
                </div>
            </section>
            <section className="flex flex-col gap-2">
                <h3 className={eyebrow}>{t('add_details.colour')}</h3>
                <div className="flex justify-between gap-2">
                    {NEW_LIST_COLORS.map((c) => (
                        <button
                            key={c}
                            type="button"
                            aria-pressed={draft.color === c}
                            aria-label={t('tasks.color_aria', { color: c })}
                            onClick={() => setDraft({ ...draft, color: c })}
                            className={`flex size-11 items-center justify-center rounded-full ${listColor(c)} ${draft.color === c ? 'border-primary border-2' : ''}`}
                        >
                            {draft.color === c && <Check className="size-4" />}
                        </button>
                    ))}
                </div>
            </section>
            <section className="flex flex-col gap-2">
                <h3 className={eyebrow}>{t('add_details.who_sees')}</h3>
                <div className="bg-surface grid grid-cols-2 rounded-full border p-1">
                    {(['family', 'private'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={draft.visibility === v}
                            onClick={() => setDraft({ ...draft, visibility: v })}
                            className={`rounded-full py-2.5 text-base font-semibold ${draft.visibility === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'}`}
                        >
                            {t(v === 'family' ? 'add_details.everyone' : 'add_details.only_me')}
                        </button>
                    ))}
                </div>
            </section>
            {note && (
                <p className="bg-surface text-primary flex gap-2 rounded-2xl px-4 py-3 text-sm">
                    <CornerDownRight className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                    {note}
                </p>
            )}
            <button
                type="button"
                onClick={onSubmit}
                disabled={busy || !draft.name.trim()}
                className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
            >
                {submitLabel}
            </button>
        </div>
    );
}

// T3d: every icon, by word or by category; the first row always fits the name. A tap picks it and steps back.
export function IconBrowser({ name, value, onPick }: { name: string; value: string; onPick: (key: string) => void }) {
    const t = useT();
    const [q, setQ] = useState('');
    const [cat, setCat] = useState<string | null>(null);
    const search = useRef<HTMLInputElement>(null);
    const fits = iconsFor(name).slice(0, 7);
    const found = q.trim() ? searchIcons(q) : null;
    const grid = (defs: typeof LIST_ICON_DEFS) => (
        <div className="grid grid-cols-7 gap-2">
            {defs.map((d) => (
                <button
                    key={d.key}
                    type="button"
                    aria-label={d.key}
                    aria-pressed={d.key === value}
                    onClick={() => onPick(d.key)}
                    className={`text-primary flex aspect-square items-center justify-center rounded-xl ${d.key === value ? 'border-primary bg-accent-subtle border-2' : 'bg-surface'}`}
                >
                    <d.icon className="size-5" />
                </button>
            ))}
        </div>
    );
    const chip = (on: boolean) =>
        `shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-semibold ${on ? 'bg-primary border-primary text-primary-foreground' : 'bg-surface-card text-primary'}`;
    return (
        <div className="flex flex-col gap-4">
            <label className="bg-surface flex min-h-12 items-center gap-2.5 rounded-2xl px-4">
                <Search className="text-muted-foreground size-5 shrink-0" />
                <input
                    ref={search}
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('add_details.search_icons')}
                    // 16px so iOS doesn't zoom in on focus.
                    className="text-primary placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-base outline-none"
                />
            </label>
            {found ? (
                found.length ? (
                    grid(found)
                ) : (
                    <p className="text-muted-foreground text-sm">{t('add_details.no_icons')}</p>
                )
            ) : (
                <>
                    <div data-scroll-x className="-mx-5 flex gap-2 overflow-x-auto px-5 [scrollbar-width:none] md:mx-0 md:px-0">
                        <button type="button" onClick={() => setCat(null)} className={chip(cat === null)}>
                            {t('add_details.icons_all')}
                        </button>
                        {ICON_CATEGORIES.map((c) => (
                            <button key={c} type="button" onClick={() => setCat(c)} className={chip(cat === c)}>
                                {t(`add_details.icons_${c}`)}
                            </button>
                        ))}
                    </div>
                    {!cat && fits.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <h3 className={eyebrow}>{t('add_details.fits', { name: name.trim() })}</h3>
                            {grid(fits)}
                        </section>
                    )}
                    {ICON_CATEGORIES.filter((c) => !cat || c === cat).map((c) => (
                        <section key={c} className="flex flex-col gap-2">
                            <h3 className={eyebrow}>{t(`add_details.icons_${c}`)}</h3>
                            {grid(LIST_ICON_DEFS.filter((d) => d.cat === c))}
                        </section>
                    ))}
                </>
            )}
        </div>
    );
}
