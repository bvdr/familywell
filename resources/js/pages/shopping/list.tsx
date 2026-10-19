import { useAddAction } from '@/lib/add-action';
import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    Calendar,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardPaste,
    Copy,
    LayoutList,
    Loader2,
    Lock,
    Mic,
    Minus,
    MoreHorizontal,
    Pencil,
    Plus,
    ShoppingCart,
    Sparkles,
    Store,
    Trash2,
    UnfoldVertical,
    UserCheck,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { AisleEditor } from '@/components/aisle-editor';
import { DuplicateWarning } from '@/components/duplicate-warning';
import { MemberAvatar } from '@/components/member-avatar';
import { Picker } from '@/components/picker';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { newClientKey, PendingAdds, send, useQueuedBought } from '@/lib/shopping-outbox';
import { aisleIcon, bumpQuantity, getVoiceLang, LIST_COLORS, LIST_ICONS, listColor, listIcon, normalizeItemName } from '@/lib/shopping';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Item = {
    id: number;
    name: string;
    quantity: string | null;
    department: string;
    bought: boolean;
    note: string | null;
    assignedTo: number | null;
    dueDate: string | null; // optional Y-m-d; surfaces the item in the dashboard Today / This Week
    store: string | null; // primary shop; null => inherits the list's store
    altStores: string[]; // also-available-at shops
};
type List = { id: number; name: string; store: string | null; color: string; icon: string; archived: boolean; visibility: string };

type Draft = {
    name: string;
    quantity: string;
    department: string;
    assigned_to: number | null;
    note: string;
    due_date: string;
    store: string;
    alt_stores: string[];
};
const emptyDraft: Draft = { name: '', quantity: '', department: 'other', assigned_to: null, note: '', due_date: '', store: '', alt_stores: [] };
const FOCUS_ALL = '__all__'; // focus-mode sentinel: show every shop's items in one run

// Visibility options for the edit-list drawer (mirrors the new-list drawer on the index).
const VISIBILITY = [
    { key: 'family', icon: Users, titleKey: 'shopping.visibility_family_title', subKey: 'shopping.visibility_family_sub' },
    { key: 'invited', icon: UserCheck, titleKey: 'shopping.visibility_invited_title', subKey: 'shopping.visibility_invited_sub' },
    { key: 'private', icon: Lock, titleKey: 'shopping.visibility_private_title', subKey: 'shopping.visibility_private_sub' },
] as const;

function AssigneeBadge({ member }: { member?: Member }) {
    if (!member) return null;
    return <MemberAvatar userId={member.id} name={member.name} className="size-6 text-[10px]" />;
}

type Parsed = { name: string; quantity: string; department: string; note: string; on_list?: boolean }; // on_list: server says an active item with this name is already on the list
// An aisle of the list's shop (custom per store, or the default Grocery set). `slug` is the value
// stored on an item's `department`; `icon` is a slug from the AISLE_ICONS pack.
type Aisle = { slug: string; name: string; icon: string };

// Minimal shape of the native SpeechRecognition API (not in the TS DOM lib; Chrome/Safari incl. iOS).
type SpeechResultEvent = { results: ArrayLike<ArrayLike<{ transcript: string }>> };
type SpeechRec = {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    onresult: (e: SpeechResultEvent) => void;
    onend: () => void;
    start: () => void;
    stop: () => void;
};
type SpeechCtor = new () => SpeechRec;

export default function ListDetail({
    list: serverList,
    items,
    members,
    aislesByStore,
    defaultAisles,
    share,
    stores,
    shops = [],
    shopIds = {},
    aiEnabled,
}: {
    list: List;
    items: Item[];
    members: Member[];
    aislesByStore: Record<string, Aisle[]>;
    defaultAisles: Aisle[];
    share: ShareState;
    stores: string[];
    shops?: string[];
    shopIds?: Record<string, number>;
    aiEnabled: boolean;
}) {
    const t = useT();
    // Optimistic list fields: rename / visibility / edit-drawer saves render instantly by shadowing the
    // server `list` with the pending patch, so every existing `list.*` read below is optimistic for free.
    // Cleared on settle (success or error), at which point the reloaded prop is authoritative again.
    const listOpt = useOptimistic<Partial<List>>();
    const list: List = { ...serverList, ...listOpt.get(serverList.id, {}) };
    const [hideCompleted, setHideCompleted] = useState(false);
    const [sortAz, setSortAz] = useState(false);
    const [focus, setFocus] = useState(false); // full-screen "shopping mode" overlay
    const [focusStore, setFocusStore] = useState(''); // which shop the focus run is for
    const [sharing, setSharing] = useState(false);
    const [editingTitle, setEditingTitle] = useState(false);
    const [title, setTitle] = useState(list.name);
    const [aislesOpen, setAislesOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null); // null = closed; 0 = new item; >0 = editing
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [extraStores, setExtraStores] = useState<string[]>([]); // stores typed in the sheet this session
    const nameRef = useRef<HTMLInputElement>(null);

    // Voice / AI dictation flow.
    const [voiceOpen, setVoiceOpen] = useState(false);
    const [stage, setStage] = useState<'voice' | 'paste' | 'review'>('voice');
    // Which input the review screen came from, so Redo / "add more" go back to the same one.
    const [mode, setMode] = useState<'voice' | 'paste'>('voice');
    const [transcript, setTranscript] = useState('');
    const [listening, setListening] = useState(false);
    // Dictation language is chosen on the module Settings page; read the saved choice on mount.
    const [voiceLang] = useState(getVoiceLang);
    const [parsing, setParsing] = useState(false);
    const [parsed, setParsed] = useState<Parsed[]>([]);
    const recRef = useRef<{ stop: () => void } | null>(null);
    // Web Speech API is a native browser feature (Chrome/Safari incl. iOS); when absent we fall back to typing.
    const speechSupported = typeof window !== 'undefined' && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

    const memberById = useMemo(() => new Map(members.map((m) => [m.id, m])), [members]);

    // Optimistic overlays: apply bought-toggle / remove locally so taps feel instant, then let the
    // background request + prop reload become authoritative. render THESE, not the raw server fields.
    const bought = useOptimistic<boolean>();
    const removed = useOptimistic<boolean>();
    // A tick queued offline (lib/shopping-outbox) wins over everything until it has synced.
    const queuedBought = useQueuedBought();
    const isBought = (i: Item) => queuedBought.get(i.id) ?? bought.get(i.id, i.bought);
    const isGone = (i: Item) => removed.get(i.id, false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.shopping'), href: '/m/shopping' },
        { title: list.name, href: route('shopping.show', list.id) },
    ];

    const liveItems = items.filter((i) => !isGone(i));
    const boughtCount = liveItems.filter((i) => isBought(i)).length;

    // A shop's aisles (custom or default). Null/unknown shop uses the default Grocery set.
    const aislesFor = (store: string | null): Aisle[] => (store ? (aislesByStore[store] ?? defaultAisles) : defaultAisles);
    // The shop an item belongs to for grouping: its own store, else the list's default store.
    const itemStore = (i: Item): string => i.store || list.store || '';
    // An item can be picked up at its primary shop or any alternative.
    const availableAt = (i: Item, store: string): boolean => itemStore(i) === store || i.altStores.includes(store);

    // Group items into a shop's aisles (walk order). Unknown departments fall into that shop's "other".
    const groupByAisle = (list: Item[], aisles: Aisle[]): { aisle: Aisle; items: Item[] }[] => {
        const bySlug = new Map(aisles.map((a) => [a.slug, a]));
        const otherSlug = bySlug.has('other') ? 'other' : '__other__';
        const buckets = new Map<string, Item[]>();
        for (const it of list) {
            const slug = bySlug.has(it.department) ? it.department : otherSlug;
            (buckets.get(slug) ?? buckets.set(slug, []).get(slug)!).push(it);
        }
        const out = aisles.filter((a) => buckets.has(a.slug)).map((a) => ({ aisle: a, items: buckets.get(a.slug)! }));
        if (buckets.has('__other__'))
            out.push({ aisle: { slug: '__other__', name: t('shopping.other'), icon: 'basket' }, items: buckets.get('__other__')! });
        return out;
    };

    const list0Store = list.store || ''; // the list's default store, sorted first
    // Order shops with the list's default first, "No store" last, the rest alphabetical.
    const storeOrder = (a: string, b: string) => (a === list0Store ? -1 : b === list0Store ? 1 : a === '' ? 1 : b === '' ? -1 : a.localeCompare(b));

    // Top-level grouping is by shop, then aisle.
    const groupByStore = (list: Item[]): { store: string; aisles: { aisle: Aisle; items: Item[] }[] }[] => {
        const byStore = new Map<string, Item[]>();
        for (const it of list) {
            const s = itemStore(it);
            (byStore.get(s) ?? byStore.set(s, []).get(s)!).push(it);
        }
        return [...byStore.keys()].sort(storeOrder).map((s) => ({ store: s, aisles: groupByAisle(byStore.get(s)!, aislesFor(s || null)) }));
    };

    const storeGroups = useMemo(() => {
        let visible = liveItems.filter((i) => (hideCompleted ? !isBought(i) : true));
        if (sortAz) visible = [...visible].sort((a, b) => a.name.localeCompare(b.name));
        return groupByStore(visible);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, list, aislesByStore, defaultAisles, hideCompleted, sortAz, bought, removed]);
    const multiStore = storeGroups.length > 1;

    // Focus mode is per shop: the store chips are every shop with something still to get (primary or
    // alternative); the selected shop's run shows the items available there, grouped by its aisles.
    const focusStores = useMemo(() => {
        const set = new Set<string>();
        for (const i of liveItems) {
            if (isBought(i)) continue;
            set.add(itemStore(i));
            for (const s of i.altStores) set.add(s);
        }
        const arr = [...set];
        arr.sort(storeOrder);
        // With more than one shop, lead with an "All shops" run so you see everything to get; the
        // per-shop chips then narrow to a single store.
        return arr.length > 1 ? [FOCUS_ALL, ...arr] : arr;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, bought, removed]);
    // Unbought items for the run, grouped by shop then aisle. "All" shows every shop (each item under
    // its primary shop); a single shop shows just what you can grab there (primary or alternative).
    const focusUnbought = liveItems.filter((i) => !isBought(i));
    const focusStoreGroups =
        focusStore === FOCUS_ALL
            ? groupByStore(focusUnbought)
            : [
                  {
                      store: focusStore,
                      aisles: groupByAisle(
                          focusUnbought.filter((i) => availableAt(i, focusStore)),
                          aislesFor(focusStore || null),
                      ),
                  },
              ];
    const focusEmpty = focusStoreGroups.every((g) => g.aisles.length === 0);
    const doneItems = liveItems.filter((i) => isBought(i) && (focusStore === FOCUS_ALL || availableAt(i, focusStore)));

    // Sends the new value, not the bare toggle: a queued tick that replays (or a fast double tap) sets the
    // state instead of flipping it again. With no connection the tick waits in the outbox.
    const toggle = (item: Item) => {
        const next = !isBought(item);
        bought.set(item.id, next);
        send(
            { kind: 'bought', key: newClientKey(), method: 'patch', url: route('shopping.items.update', item.id), data: { bought: next }, itemId: item.id },
            { preserveScroll: true, preserveState: true, onFinish: () => bought.clear(item.id) },
        );
    };
    const remove = (item: Item) => {
        removed.set(item.id, true);
        router.delete(route('shopping.items.destroy', item.id), {
            preserveScroll: true,
            preserveState: true,
            onError: () => removed.clear(item.id),
        });
    };

    const patchList = (data: Partial<List>) => {
        listOpt.set(list.id, { ...listOpt.get(list.id, {}), ...data });
        router.patch(route('shopping.lists.update', list.id), data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => listOpt.clear(list.id),
            onError: () => listOpt.clear(list.id),
        });
    };

    const saveTitle = () => {
        setEditingTitle(false);
        if (title.trim() && title !== list.name) patchList({ name: title.trim() });
    };

    // Full "Edit list" drawer (name, shop, colour/icon, visibility) — like the new-list drawer plus a
    // shop. Setting the shop makes every item WITHOUT its own shop inherit it (via itemStore/itemShops),
    // so no item rows are rewritten; clearing it drops them back to "no shop".
    const [editingList, setEditingList] = useState(false);
    const [listForm, setListForm] = useState({
        name: list.name,
        store: list.store ?? '',
        color: list.color,
        icon: list.icon,
        visibility: list.visibility,
    });
    const openEditList = () => {
        setListForm({ name: list.name, store: list.store ?? '', color: list.color, icon: list.icon, visibility: list.visibility });
        setEditingList(true);
    };
    const saveList = () => {
        if (!listForm.name.trim()) return;
        patchList({
            name: listForm.name.trim(),
            store: listForm.store || null,
            color: listForm.color,
            icon: listForm.icon,
            visibility: listForm.visibility,
        });
        setEditingList(false);
    };

    const openNew = () => {
        // Default a new item to the list's store (or the shop you're focused on) so single-store lists
        // need no store tap; the aisle chips then come from that shop.
        skipAutosave.current = true;
        setDraft({ ...emptyDraft, store: (focus && focusStore !== FOCUS_ALL ? focusStore : list.store) || '' });
        setEditingId(0);
    };
    useAddAction(() => openNew());
    const openEdit = (item: Item) => {
        skipAutosave.current = true;
        setDraft({
            name: item.name,
            quantity: item.quantity ?? '',
            department: item.department,
            assigned_to: item.assignedTo,
            note: item.note ?? '',
            due_date: item.dueDate ?? '',
            store: item.store ?? list.store ?? '',
            alt_stores: item.altStores,
        });
        setEditingId(item.id);
    };
    const buildPayload = (d: Draft) => ({ ...d, name: d.name.trim(), due_date: d.due_date || null, store: d.store || null });
    const patchItem = (id: number) =>
        router.patch(route('shopping.items.update', id), buildPayload(draft), { preserveScroll: true, preserveState: true });
    // Only the "Add item" (new item) button commits; editing an existing item auto-saves (below).
    // `adding` guards against a double-tap creating duplicate items.
    const [adding, setAdding] = useState(false);
    // Soft duplicate warning from the server ("Milk is already on this list"): shown in the footer with
    // Add anyway / Bump / Cancel. Cleared whenever the name changes or the sheet closes.
    const [dupWarning, setDupWarning] = useState<string | null>(null);
    const addItem = (addAnyway = false) => {
        if (!draft.name.trim() || adding) return;
        setAdding(true);
        const data = { ...buildPayload(draft), add_anyway: addAnyway, client_key: newClientKey() };
        send(
            { kind: 'add', key: data.client_key, method: 'post', url: route('shopping.items.store', list.id), data, page: window.location.pathname, name: data.name },
            {
                preserveScroll: true,
                onSuccess: () => setEditingId(null),
                onQueued: () => setEditingId(null),
                onError: (errors) => setDupWarning(errors.add_anyway ?? null),
                onFinish: () => setAdding(false),
            },
        );
    };
    // "Bump": +1 on the existing item instead of adding a second row. The match is by the same normalized
    // name the server used; if the quantity is free text that can't be bumped the option isn't offered.
    const dupItem = dupWarning ? liveItems.find((i) => !isBought(i) && normalizeItemName(i.name) === normalizeItemName(draft.name)) : undefined;
    const dupBump = dupItem ? bumpQuantity(dupItem.quantity) : null;
    const bumpExisting = () => {
        if (!dupItem || dupBump === null) return;
        router.patch(route('shopping.items.update', dupItem.id), { quantity: dupBump }, { preserveScroll: true, preserveState: true });
        setEditingId(null);
    };
    useEffect(() => setDupWarning(null), [draft.name, editingId]);
    // Auto-save edits: debounce any field change to a PATCH; skip the draft set on open, and never
    // save an empty name. The modal's close handler flushes the last edit the debounce hasn't sent.
    const skipAutosave = useRef(true);
    useEffect(() => {
        if (!editingId) return; // new items (0) commit via the Add button, not autosave
        if (skipAutosave.current) {
            skipAutosave.current = false;
            return;
        }
        if (!draft.name.trim()) return;
        const t = setTimeout(() => patchItem(editingId), 500);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft, editingId]);
    // Shops offered in the item sheet: the family's known stores plus the list's store, deduped.
    const storeOptions = [...new Set([list.store, ...stores].filter(Boolean) as string[])];
    // Plus any store typed via the "+" this session and the current draft store, so a brand-new store
    // shows as a selected chip immediately (it becomes a real family store once the item is saved).
    const chipStores = [...new Set([...storeOptions, ...extraStores, draft.store].filter(Boolean))];

    // Numeric stepper that no-ops on free-text quantities like "1 gallon".
    const bump = (delta: number) =>
        setDraft((d) => {
            const n = parseInt(d.quantity, 10);
            return { ...d, quantity: String(Math.max(0, (isNaN(n) ? 0 : n) + delta)) };
        });

    const openVoice = (from: 'voice' | 'paste' = 'voice') => {
        setTranscript('');
        setParsed([]);
        setMode(from);
        setStage(from);
        setVoiceOpen(true);
    };
    // "Paste a list": same overlay + review + bulk-add as voice, just a textarea and the line-aware
    // server split (shopping.paste) instead of dictation.
    const openPaste = () => openVoice('paste');
    const closeVoice = () => {
        recRef.current?.stop();
        setListening(false);
        setVoiceOpen(false);
    };

    // Start dictation in a specific language. The Web Speech API can't auto-detect the spoken language,
    // it needs a BCP-47 code up front, so the user picks via the chips (default Romanian, remembered).
    const startListening = (lang: string) => {
        const w = window as unknown as { SpeechRecognition?: SpeechCtor; webkitSpeechRecognition?: SpeechCtor };
        const SR = w.SpeechRecognition ?? w.webkitSpeechRecognition;
        if (!SR) return;
        const rec = new SR();
        rec.continuous = true;
        rec.interimResults = true;
        rec.lang = lang;
        rec.onresult = (e: SpeechResultEvent) => {
            let text = '';
            for (let i = 0; i < e.results.length; i++) text += e.results[i][0].transcript;
            setTranscript(text);
        };
        rec.onend = () => setListening(false);
        rec.start();
        recRef.current = rec;
        setListening(true);
    };
    const toggleListening = () => {
        if (listening) recRef.current?.stop();
        else startListening(voiceLang);
    };

    // Offline fallback (used only if the AI parse can't be reached): split a run-on list on commas,
    // semicolons, newlines and conjunctions in the languages our users actually speak (en "and/then",
    // ro "și/şi", es "y", de "und", it/pt "e"). No aisle guessing (all 'other'). It can't separate
    // space-only lists ("milk eggs") the way the AI can, but it beats leaving the whole line as one item.
    const naiveParse = (text: string): Parsed[] =>
        text
            // Drop a leading command verb ("buy", "get", "cumpără", ...) so it isn't glued to the first item.
            .replace(/^\s*(buy|get|grab|need|cump[ăa]r[ăa]|ia)\b[:,]?\s*/i, '')
            .split(/[,;\n]|\band\b|\bthen\b|\bplus\b|&|\bși\b|\bşi\b|\by\b|\bund\b|\be\b/giu)
            .map((s) => s.replace(/^\s*(a few|some|a|an|the|ni[șs]te|un|o)\s+/i, '').trim())
            .filter(Boolean)
            .map((name) => ({ name, quantity: '', department: 'other', note: '' }));

    const runParse = async () => {
        recRef.current?.stop();
        setListening(false);
        const text = transcript.trim();
        if (!text) return;
        setParsing(true);
        try {
            const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            // Relative URL (route(..., false)): a same-origin fetch must hit the exact origin the user is
            // on, or the session cookie isn't sent and the AI parse silently fails to the naive split.
            // Paste hits the always-available line splitter (AI-sorted when configured); voice hits the
            // AI-only parse, which 404s without a key and drops us to the naive split.
            const res = await fetch(route(mode === 'paste' ? 'shopping.paste' : 'shopping.parse', list.id, false), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(mode === 'paste' ? { text } : { transcript: text }),
            });
            const items: Parsed[] = res.ok ? (await res.json()).items : naiveParse(text);
            setParsed((prev) => [...prev, ...items]);
        } catch {
            setParsed((prev) => [...prev, ...naiveParse(text)]);
        } finally {
            setParsing(false);
            setStage('review');
        }
    };

    // `addingAll` guards against a double-tap posting the batch twice (the reported voice-dictation
    // duplication): a ref, not state, so a second synchronous tap sees the flag immediately, before
    // React re-renders the disabled button.
    const addingAll = useRef(false);
    const [addingAllState, setAddingAllState] = useState(false);
    const addAll = () => {
        if (parsed.length === 0 || addingAll.current) return;
        addingAll.current = true;
        setAddingAllState(true);
        router.post(
            route('shopping.items.bulk', list.id),
            { items: parsed },
            {
                preserveScroll: true,
                onSuccess: closeVoice,
                onFinish: () => {
                    addingAll.current = false;
                    setAddingAllState(false);
                },
            },
        );
    };

    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label={t('shopping.share_list')}
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={t('shopping.list_options')}
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setSharing(true)}>
                        <UserPlus className="size-4" /> {t('shopping.share_list')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setTimeout(openEditList, 0)}>
                        <Pencil className="size-4" /> {t('shopping.edit_list')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setAislesOpen(true)}>
                        <LayoutList className="size-4" /> {t('shopping.edit_departments')}
                    </DropdownMenuItem>
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <UnfoldVertical className="size-4" /> {t('shopping.sort_items')}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem onSelect={() => setSortAz(false)}>
                                {!sortAz && <Check className="size-4" />} {t('shopping.by_aisle')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setSortAz(true)}>
                                {sortAz && <Check className="size-4" />} {t('shopping.a_to_z')}
                            </DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuSeparator />
                    {list.visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'private' })}>
                            <Lock className="size-4" /> {t('shopping.make_private')}
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'family' })}>
                            <Users className="size-4" /> {t('shopping.share_with_family')}
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => router.post(route('shopping.lists.duplicate', list.id))}>
                        <Copy className="size-4" /> {t('shopping.duplicate_list')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => patchList({ archived: true })}>
                        <Archive className="size-4" /> {t('shopping.archive_list')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm(t('shopping.confirm_delete_list'))) router.delete(route('shopping.lists.destroy', list.id));
                        }}
                    >
                        <Trash2 className="size-4" /> {t('shopping.delete_list')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={list.name} />
            <div className="mx-auto w-full max-w-2xl">
                <header className="bg-background/95 sticky top-0 z-20 px-4 pt-3 pb-3 backdrop-blur-sm">
                    {editingTitle ? (
                        <input
                            autoFocus
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            onBlur={saveTitle}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') saveTitle();
                                if (e.key === 'Escape') {
                                    setTitle(list.name);
                                    setEditingTitle(false);
                                }
                            }}
                            aria-label={t('shopping.list_name')}
                            className="font-heading text-primary border-border focus:border-accent w-full rounded-lg border bg-transparent px-1 text-2xl outline-none"
                        />
                    ) : (
                        <div className="flex items-center justify-between gap-3">
                            <button type="button" onClick={() => setEditingTitle(true)} className="group flex min-w-0 items-center gap-2 text-left">
                                <h1 className="font-heading text-primary truncate text-2xl">{list.name}</h1>
                                {list.visibility === 'private' && <Lock className="text-muted-foreground size-4 shrink-0" />}
                            </button>
                            {/* Count sits inline with the title; no progress bar. */}
                            <div className="text-muted-foreground shrink-0 text-sm tabular-nums">
                                {t('shopping.done_count', { bought: boughtCount, total: liveItems.length })}
                            </div>
                        </div>
                    )}
                </header>

                <div className="flex flex-col gap-4 p-4">
                    <button
                        type="button"
                        role="switch"
                        aria-checked={hideCompleted}
                        onClick={() => setHideCompleted((v) => !v)}
                        className="text-muted-foreground flex items-center justify-between text-sm"
                    >
                        <span>{t('shopping.hide_completed')}</span>
                        <span className={`relative h-6 w-10 rounded-full transition-colors ${hideCompleted ? 'bg-accent-strong' : 'bg-border'}`}>
                            <span
                                className={`bg-background absolute top-0.5 size-5 rounded-full shadow transition-all ${hideCompleted ? 'left-[1.125rem]' : 'left-0.5'}`}
                            />
                        </span>
                    </button>

                    {storeGroups.map((sg) => (
                        <div key={sg.store || '__none__'} className="flex flex-col gap-2">
                            {/* Store header only when the list actually spans shops, so single-store lists stay clean.
                                Tapping it opens that shop's page (the cross-list view for that shop). */}
                            {multiStore &&
                                (sg.store && shopIds[sg.store] ? (
                                    <Link
                                        href={`/m/shopping/shops/${shopIds[sg.store]}`}
                                        className="text-primary border-border/60 hover:border-accent mt-1 flex items-center gap-1.5 border-b pb-1 text-sm font-semibold"
                                    >
                                        <Store className="size-4" /> {sg.store}
                                        <ChevronRight className="ml-auto size-4 opacity-50" />
                                    </Link>
                                ) : (
                                    <div className="text-primary border-border/60 mt-1 flex items-center gap-1.5 border-b pb-1 text-sm font-semibold">
                                        <Store className="size-4" /> {sg.store || t('shopping.no_store')}
                                    </div>
                                ))}
                            {sg.aisles.map((group) => {
                                const AisleIcon = aisleIcon(group.aisle.icon);
                                return (
                                    <section key={group.aisle.slug} className="flex flex-col gap-2">
                                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                            <AisleIcon className="size-3.5" /> {group.aisle.name}
                                        </h2>
                                        {group.items.map((item) => (
                                            <div key={item.id} className="bg-surface-card group flex items-center gap-3 rounded-xl border p-3">
                                                <button
                                                    type="button"
                                                    onClick={() => toggle(item)}
                                                    aria-label={isBought(item) ? t('shopping.mark_not_bought') : t('shopping.mark_bought')}
                                                    className={`flex size-6 shrink-0 items-center justify-center rounded-md border transition-colors ${
                                                        isBought(item)
                                                            ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                                            : 'border-border'
                                                    }`}
                                                >
                                                    {isBought(item) && <Check className="size-4" />}
                                                </button>
                                                <button type="button" onClick={() => openEdit(item)} className="min-w-0 flex-1 text-left">
                                                    <div
                                                        className={`text-primary text-sm font-medium ${isBought(item) ? 'text-muted-foreground line-through' : ''}`}
                                                    >
                                                        {item.name}
                                                    </div>
                                                    {item.quantity && <div className="text-muted-foreground text-xs">{item.quantity}</div>}
                                                    {item.altStores.length > 0 && (
                                                        <div className="text-text-muted mt-0.5 text-[11px]">
                                                            {t('shopping.also_at', { stores: item.altStores.join(', ') })}
                                                        </div>
                                                    )}
                                                </button>
                                                {item.assignedTo != null && <AssigneeBadge member={memberById.get(item.assignedTo)} />}
                                                <button
                                                    type="button"
                                                    onClick={() => remove(item)}
                                                    aria-label={t('shopping.remove_item')}
                                                    className="text-muted-foreground hover:text-destructive flex size-7 items-center justify-center rounded-lg opacity-0 transition-opacity group-hover:opacity-100"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </div>
                                        ))}
                                    </section>
                                );
                            })}
                        </div>
                    ))}

                    {liveItems.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">{t('shopping.no_items_yet')}</p>}

                    <PendingAdds />

                    <div className="sticky bottom-4 flex flex-col gap-2">
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={openNew} className="flex-1">
                                <Plus className="size-4" /> {t('shopping.add_item')}
                            </Button>
                            <Button type="button" variant="outline" size="icon" onClick={() => openVoice()} aria-label={t('shopping.add_by_voice')}>
                                <Mic className="size-4" />
                            </Button>
                            <Button type="button" variant="outline" size="icon" onClick={openPaste} aria-label={t('shopping.paste_list')}>
                                <ClipboardPaste className="size-4" />
                            </Button>
                        </div>
                        {liveItems.some((i) => !isBought(i)) && (
                            <Button
                                type="button"
                                onClick={() => {
                                    setFocusStore(focusStores[0] ?? '');
                                    setFocus(true);
                                }}
                                className="w-full shadow-[var(--shadow-fw-md)]"
                            >
                                <ShoppingCart className="size-4" /> {t('shopping.start_shopping')}
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            {/* Focus / Shopping mode: a distraction-free, big-tap full-screen run through the store.
                Portaled to <body> so it escapes the layout's stacking context and covers the tab bar. */}
            {focus &&
                createPortal(
                    <div className="bg-primary text-primary-foreground fixed inset-0 z-[60] flex flex-col">
                        <div className="px-5 pt-[calc(1rem+env(safe-area-inset-top))] pb-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-accent-strong-foreground/60 flex items-center gap-1.5 text-[11px] font-semibold tracking-widest uppercase">
                                        {t('shopping.focus_mode')}
                                    </div>
                                    <div className="text-accent-strong-foreground/80 mt-1 flex items-center gap-1.5 text-sm">
                                        <Store className="size-4" />{' '}
                                        {focusStore === FOCUS_ALL ? t('shopping.all_shops') : focusStore || list.store || t('shopping.no_store')}
                                    </div>
                                    <h2 className="font-heading mt-1 truncate text-2xl">{list.name}</h2>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setFocus(false)}
                                    aria-label={t('shopping.exit_shopping_mode')}
                                    className="hover:bg-primary-foreground/10 flex size-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>
                            {/* Switch which shop you're at; each shows the items you can grab there (primary or alt). */}
                            {focusStores.length > 1 && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {focusStores.map((s) => (
                                        <button
                                            key={s || '__none__'}
                                            type="button"
                                            onClick={() => setFocusStore(s)}
                                            className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                                focusStore === s
                                                    ? 'bg-primary-foreground text-primary'
                                                    : 'bg-primary-foreground/15 text-accent-strong-foreground/80'
                                            }`}
                                        >
                                            {s === FOCUS_ALL ? t('shopping.all_shops') : s || t('shopping.no_store')}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="flex-1 space-y-6 overflow-y-auto px-5 pb-[calc(2rem+env(safe-area-inset-bottom))]">
                            {focusStoreGroups.map((sg) =>
                                sg.aisles.length === 0 ? null : (
                                    <div key={sg.store || '__none__'} className="space-y-3">
                                        {/* In the All-shops run, label each shop section. */}
                                        {focusStore === FOCUS_ALL && (
                                            <div className="text-accent-strong-foreground flex items-center gap-1.5 text-sm font-semibold">
                                                <Store className="size-4" /> {sg.store || t('shopping.no_store')}
                                            </div>
                                        )}
                                        {sg.aisles.map((group) => {
                                            const AisleIcon = aisleIcon(group.aisle.icon);
                                            return (
                                                <section key={group.aisle.slug} className="space-y-2">
                                                    <h3 className="text-accent-strong-foreground/60 flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                                        <AisleIcon className="size-3.5" /> {group.aisle.name}
                                                    </h3>
                                                    {group.items.map((item) => (
                                                        <button
                                                            key={item.id}
                                                            type="button"
                                                            onClick={() => toggle(item)}
                                                            className="border-primary-foreground/15 hover:bg-primary-foreground/5 flex w-full items-center gap-3 rounded-2xl border p-4 text-left transition-colors"
                                                        >
                                                            <span className="border-primary-foreground/40 flex size-6 shrink-0 rounded-full border-2" />
                                                            <div className="min-w-0 flex-1">
                                                                <div className="font-medium">{item.name}</div>
                                                                {item.quantity && (
                                                                    <div className="text-accent-strong-foreground/60 text-sm">{item.quantity}</div>
                                                                )}
                                                            </div>
                                                        </button>
                                                    ))}
                                                </section>
                                            );
                                        })}
                                    </div>
                                ),
                            )}

                            {focusEmpty && <p className="text-accent-strong-foreground/80 py-10 text-center">{t('shopping.everything_done')}</p>}

                            {doneItems.length > 0 && (
                                <section className="space-y-2">
                                    <h3 className="text-accent-strong-foreground/50 text-xs font-semibold tracking-widest uppercase">
                                        {t('shopping.done_heading')}
                                    </h3>
                                    {doneItems.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => toggle(item)}
                                            className="flex w-full items-center gap-3 rounded-2xl p-4 text-left opacity-60"
                                        >
                                            <span className="bg-primary-foreground/90 text-primary flex size-6 shrink-0 items-center justify-center rounded-full">
                                                <Check className="size-4" />
                                            </span>
                                            <div className="min-w-0 flex-1 line-through">{item.name}</div>
                                        </button>
                                    ))}
                                </section>
                            )}
                        </div>
                    </div>,
                    document.body,
                )}

            {/* Voice dictation + AI review, portaled full-screen over everything. */}
            {voiceOpen &&
                createPortal(
                    <div className="bg-primary text-primary-foreground fixed inset-0 z-[60] flex flex-col">
                        {stage !== 'review' ? (
                            <>
                                <div className="flex justify-end px-5 pt-[calc(1rem+env(safe-area-inset-top))]">
                                    <button
                                        type="button"
                                        onClick={closeVoice}
                                        aria-label={t('shopping.close')}
                                        className="hover:bg-primary-foreground/10 flex size-9 items-center justify-center rounded-lg"
                                    >
                                        <X className="size-5" />
                                    </button>
                                </div>
                                <div className="flex flex-1 flex-col items-center justify-center gap-6 px-6 text-center">
                                    {stage === 'voice' && speechSupported && (
                                        <button
                                            type="button"
                                            onClick={toggleListening}
                                            aria-label={listening ? t('shopping.stop_listening') : t('shopping.start_listening')}
                                            className="bg-primary-foreground/15 relative flex size-28 items-center justify-center rounded-full"
                                        >
                                            {listening && <span className="bg-primary-foreground/20 absolute inset-0 animate-ping rounded-full" />}
                                            <Mic className="size-11" />
                                        </button>
                                    )}
                                    {listening && (
                                        <div className="flex h-8 items-end gap-1">
                                            {[0, 1, 2, 3, 4, 5, 6].map((i) => (
                                                <span
                                                    key={i}
                                                    className="bg-primary-foreground/70 w-1 animate-pulse rounded-full"
                                                    style={{ height: `${8 + ((i * 7) % 24)}px`, animationDelay: `${i * 90}ms` }}
                                                />
                                            ))}
                                        </div>
                                    )}
                                    {stage === 'voice' && speechSupported ? (
                                        transcript ? (
                                            <p className="text-lg leading-relaxed">{transcript}</p>
                                        ) : (
                                            <p className="text-accent-strong-foreground/70 text-sm">{t('shopping.voice_prompt')}</p>
                                        )
                                    ) : (
                                        <>
                                            <p className="text-accent-strong-foreground/70 text-sm">
                                                {t(stage === 'paste' ? 'shopping.paste_prompt' : 'shopping.type_prompt')}
                                            </p>
                                            <textarea
                                                autoFocus
                                                value={transcript}
                                                onChange={(e) => setTranscript(e.target.value)}
                                                placeholder={t(stage === 'paste' ? 'shopping.paste_placeholder' : 'shopping.voice_placeholder')}
                                                className={`border-primary-foreground/20 placeholder:text-accent-strong-foreground/40 w-full rounded-xl border bg-transparent p-3 text-base outline-none ${stage === 'paste' ? 'min-h-56' : 'min-h-28'}`}
                                            />
                                        </>
                                    )}
                                </div>
                                <div className="px-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                                    <button
                                        type="button"
                                        onClick={runParse}
                                        disabled={!transcript.trim() || parsing}
                                        className="bg-primary-foreground text-primary flex w-full items-center justify-center gap-2 rounded-xl py-3.5 font-medium disabled:opacity-50"
                                    >
                                        {parsing ? <Loader2 className="size-5 animate-spin" /> : <Check className="size-5" />}
                                        {parsing ? t('shopping.sorting') : t('shopping.done_button')}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="flex items-center justify-between px-5 pt-[calc(1rem+env(safe-area-inset-top))] pb-2">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setParsed([]);
                                            setTranscript('');
                                            setStage(mode);
                                        }}
                                        className="text-accent-strong-foreground/80 flex items-center gap-1 text-sm"
                                    >
                                        <ChevronLeft className="size-4" /> {t('shopping.redo')}
                                    </button>
                                    {aiEnabled && (
                                        <span className="bg-primary-foreground/15 flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium">
                                            <Sparkles className="size-3.5" /> {t('shopping.organized_by_ai')}
                                        </span>
                                    )}
                                </div>
                                <div className="flex-1 space-y-4 overflow-y-auto px-5 pb-[calc(9rem+env(safe-area-inset-bottom))]">
                                    <div>
                                        <h2 className="font-heading text-2xl">
                                            {t(mode === 'paste' ? 'shopping.pasted_heading' : 'shopping.heard_heading')}
                                        </h2>
                                        {transcript && mode === 'voice' && (
                                            <p className="text-accent-strong-foreground/70 mt-2 text-sm italic">"{transcript}"</p>
                                        )}
                                        <p className="text-accent-strong-foreground/70 mt-2 text-sm">
                                            {t(parsed.length === 1 ? 'shopping.items_sorted_one' : 'shopping.items_sorted_other', {
                                                n: parsed.length,
                                            })}
                                        </p>
                                    </div>
                                    {aislesFor(list.store)
                                        .map((a) => ({
                                            aisle: a,
                                            entries: parsed.map((it, idx) => ({ it, idx })).filter((e) => e.it.department === a.slug),
                                        }))
                                        .filter((g) => g.entries.length > 0)
                                        .map((group) => {
                                            const AisleIcon = aisleIcon(group.aisle.icon);
                                            return (
                                                <section key={group.aisle.slug} className="space-y-2">
                                                    <h3 className="text-accent-strong-foreground/60 flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                                        <AisleIcon className="size-3.5" /> {group.aisle.name}
                                                    </h3>
                                                    {group.entries.map(({ it, idx }) => (
                                                        <div
                                                            key={idx}
                                                            className="border-primary-foreground/15 flex items-center gap-3 rounded-xl border p-3"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                {it.on_list && (
                                                                    <span className="border-primary-foreground/25 mb-1 inline-block rounded-md border px-1.5 py-0.5 text-[10px] font-semibold tracking-wider uppercase opacity-80">
                                                                        {t('shopping.on_list_tag')}
                                                                    </span>
                                                                )}
                                                                <input
                                                                    value={it.name}
                                                                    onChange={(e) =>
                                                                        setParsed((p) =>
                                                                            p.map((x, i) => (i === idx ? { ...x, name: e.target.value } : x)),
                                                                        )
                                                                    }
                                                                    className="w-full bg-transparent font-medium outline-none"
                                                                />
                                                                <input
                                                                    value={[it.quantity, it.note].filter(Boolean).join(' · ')}
                                                                    onChange={(e) =>
                                                                        setParsed((p) =>
                                                                            p.map((x, i) =>
                                                                                i === idx ? { ...x, quantity: e.target.value, note: '' } : x,
                                                                            ),
                                                                        )
                                                                    }
                                                                    placeholder={t('shopping.quantity')}
                                                                    className="text-accent-strong-foreground/60 w-full bg-transparent text-sm outline-none"
                                                                />
                                                            </div>
                                                            <button
                                                                type="button"
                                                                onClick={() => setParsed((p) => p.filter((_, i) => i !== idx))}
                                                                aria-label={t('shopping.remove')}
                                                                className="hover:bg-primary-foreground/10 flex size-8 shrink-0 items-center justify-center rounded-lg"
                                                            >
                                                                <X className="size-4" />
                                                            </button>
                                                        </div>
                                                    ))}
                                                </section>
                                            );
                                        })}
                                    {parsed.length === 0 && (
                                        <p className="text-accent-strong-foreground/70 py-8 text-center text-sm">{t('shopping.nothing_left')}</p>
                                    )}
                                </div>
                                <div className="bg-accent-strong absolute inset-x-0 bottom-0 space-y-2 px-5 pt-2 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setTranscript('');
                                            setStage(mode);
                                        }}
                                        className="border-primary-foreground/25 flex w-full items-center justify-center gap-2 rounded-xl border py-3 text-sm font-medium"
                                    >
                                        {mode === 'paste' ? <ClipboardPaste className="size-4" /> : <Mic className="size-4" />}{' '}
                                        {t(mode === 'paste' ? 'shopping.add_more_by_paste' : 'shopping.add_more_by_voice')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={addAll}
                                        disabled={parsed.length === 0 || addingAllState}
                                        className="bg-primary-foreground text-primary flex w-full items-center justify-center gap-2 rounded-xl py-3.5 font-medium disabled:opacity-50"
                                    >
                                        <Check className="size-5" />{' '}
                                        {t(parsed.length === 1 ? 'shopping.add_n_items_one' : 'shopping.add_n_items_other', { n: parsed.length })}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>,
                    document.body,
                )}

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}

            <AisleEditor open={aislesOpen} onOpenChange={setAislesOpen} listId={list.id} stores={storeOptions} initialStore={list.store ?? ''} />

            {/* Edit list: name, shop, colour/icon, visibility. Mirrors the new-list drawer plus a shop. */}
            <ResponsiveModal
                open={editingList}
                onOpenChange={setEditingList}
                title={t('shopping.edit_list')}
                footer={
                    <Button className="w-full" disabled={!listForm.name.trim()} onClick={saveList}>
                        <Check className="size-4" /> {t('shopping.save')}
                    </Button>
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="flex items-center gap-3">
                        <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(listForm.color)}`}>
                            {(() => {
                                const Icon = listIcon(listForm.icon);
                                return <Icon className="size-6" />;
                            })()}
                        </span>
                        <Input
                            value={listForm.name}
                            onChange={(e) => setListForm((f) => ({ ...f, name: e.target.value }))}
                            placeholder={t('shopping.list_name')}
                            className="text-base"
                        />
                    </div>

                    {/* Shop: single pick. Items without their own shop inherit this one. */}
                    <div className="grid gap-2">
                        <div className="flex items-center justify-between">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.list_shop')}</Label>
                            {listForm.store && (
                                <button
                                    type="button"
                                    onClick={() => setListForm((f) => ({ ...f, store: '' }))}
                                    className="text-muted-foreground hover:text-primary text-xs"
                                >
                                    {t('shopping.clear')}
                                </button>
                            )}
                        </div>
                        <Picker
                            searchable
                            placeholder={t('shopping.pick_shop')}
                            options={[...new Set([...shops, ...storeOptions, ...extraStores, listForm.store].filter(Boolean))].map((s) => ({
                                value: s,
                                label: s,
                                icon: Store,
                            }))}
                            selected={listForm.store ? [listForm.store] : []}
                            onChange={(next) => setListForm((f) => ({ ...f, store: next[0] ?? '' }))}
                            onCreate={(name) => {
                                setExtraStores((prev) => [...new Set([...prev, name])]);
                                setListForm((f) => ({ ...f, store: name }));
                            }}
                        />
                        <p className="text-muted-foreground text-xs">{t('shopping.list_shop_hint')}</p>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.color_icon')}</Label>
                        <div className="flex flex-wrap gap-2">
                            {Object.keys(LIST_COLORS).map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => setListForm((f) => ({ ...f, color: c }))}
                                    aria-label={t('shopping.color_aria', { c })}
                                    className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${listForm.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                                >
                                    {listForm.color === c && <Check className="size-4" />}
                                </button>
                            ))}
                        </div>
                        <div className="mt-1 flex flex-wrap gap-2">
                            {Object.keys(LIST_ICONS).map((ic) => {
                                const Icon = listIcon(ic);
                                return (
                                    <button
                                        key={ic}
                                        type="button"
                                        onClick={() => setListForm((f) => ({ ...f, icon: ic }))}
                                        aria-label={t('shopping.icon_aria', { ic })}
                                        className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                                            listForm.icon === ic
                                                ? 'border-primary bg-accent-subtle text-primary'
                                                : 'border-border text-muted-foreground'
                                        }`}
                                    >
                                        <Icon className="size-4.5" />
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.who_can_view')}</Label>
                        <div className="flex flex-col gap-2">
                            {VISIBILITY.map((v) => {
                                const active = listForm.visibility === v.key;
                                return (
                                    <button
                                        key={v.key}
                                        type="button"
                                        onClick={() => setListForm((f) => ({ ...f, visibility: v.key }))}
                                        className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${
                                            active ? 'border-primary bg-accent-subtle' : 'border-border'
                                        }`}
                                    >
                                        <v.icon className="text-primary size-5 shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-primary text-sm font-medium">{t(v.titleKey)}</div>
                                            <div className="text-muted-foreground text-xs">{t(v.subKey)}</div>
                                        </div>
                                        {active && <Check className="text-primary size-4 shrink-0" />}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </ResponsiveModal>

            {/* Add / edit item. Editing auto-saves; only new items need a commit button. */}
            <ResponsiveModal
                open={editingId !== null}
                onOpenChange={(v) => {
                    if (v) return;
                    // Flush the latest edit before closing (the debounce may not have fired yet).
                    if (editingId && draft.name.trim()) patchItem(editingId);
                    setEditingId(null);
                }}
                title={editingId ? t('shopping.edit_item') : t('shopping.add_item_title')}
                autoFocusRef={nameRef}
                footer={
                    editingId ? undefined : dupWarning ? (
                        <DuplicateWarning message={dupWarning}>
                            <Button size="sm" disabled={adding} onClick={() => addItem(true)}>
                                {t('shopping.add_anyway')}
                            </Button>
                            {dupBump !== null && (
                                <Button size="sm" variant="outline" onClick={bumpExisting}>
                                    {t('shopping.bump_to', { n: dupBump })}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" onClick={() => setEditingId(null)}>
                                {t('common.cancel')}
                            </Button>
                        </DuplicateWarning>
                    ) : (
                        <Button className="w-full" disabled={!draft.name.trim() || adding} onClick={() => addItem()}>
                            <Plus className="size-4" /> {t('shopping.add_item')}
                        </Button>
                    )
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.item_label')}</Label>
                        <Input
                            ref={nameRef}
                            value={draft.name}
                            onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
                            placeholder={t('shopping.item_placeholder_list')}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.quantity')}</Label>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => bump(-1)}
                                aria-label={t('shopping.decrease')}
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Minus className="size-4" />
                            </button>
                            <Input
                                value={draft.quantity}
                                onChange={(e) => setDraft((d) => ({ ...d, quantity: e.target.value }))}
                                placeholder={t('shopping.quantity_placeholder')}
                                className="text-center"
                            />
                            <button
                                type="button"
                                onClick={() => bump(1)}
                                aria-label={t('shopping.increase')}
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Plus className="size-4" />
                            </button>
                        </div>
                    </div>

                    {/* Shops: one searchable multi-pick. The first selected is the item's primary store
                        (used for grouping in the list); the rest are its "also available at" shops. */}
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.tab_shops')}</Label>
                        <Picker
                            multi
                            searchable
                            placeholder={t('shopping.pick_shops')}
                            options={[...new Set([...shops, ...chipStores, draft.store, ...draft.alt_stores].filter(Boolean))].map((s) => ({
                                value: s,
                                label: s,
                                icon: Store,
                            }))}
                            selected={[...new Set([draft.store, ...draft.alt_stores].filter(Boolean))]}
                            onChange={(next) => {
                                const uniq = [...new Set(next)];
                                setDraft((d) => ({ ...d, store: uniq[0] ?? '', alt_stores: uniq.slice(1) }));
                            }}
                            onCreate={(name) => setExtraStores((prev) => [...new Set([...prev, name])])}
                        />
                    </div>

                    {/* Department: single pick, from the primary shop's departments. */}
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('shopping.department_label')}
                        </Label>
                        <Picker
                            placeholder={t('shopping.pick_department')}
                            options={aislesFor(draft.store || list.store).map((a) => ({ value: a.slug, label: a.name, icon: aisleIcon(a.icon) }))}
                            selected={[draft.department]}
                            onChange={(next) => setDraft((d) => ({ ...d, department: next[0] ?? 'other' }))}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.for_label')}</Label>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setDraft((d) => ({ ...d, assigned_to: null }))}
                                className={`rounded-full border px-3 py-1.5 text-sm ${draft.assigned_to === null ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'}`}
                            >
                                {t('shopping.anyone')}
                            </button>
                            {members.map((m) => (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => setDraft((d) => ({ ...d, assigned_to: m.id }))}
                                    className={`rounded-full border px-3 py-1.5 text-sm ${draft.assigned_to === m.id ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'}`}
                                >
                                    {m.name}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('shopping.note_label')}</Label>
                        <Input
                            value={draft.note}
                            onChange={(e) => setDraft((d) => ({ ...d, note: e.target.value }))}
                            placeholder={t('shopping.note_placeholder')}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('shopping.due_date_label')}
                        </Label>
                        <div className="border-border flex h-10 w-full items-center gap-2 rounded-md border px-3">
                            <Calendar className="text-muted-foreground size-4 shrink-0" />
                            <input
                                type="date"
                                value={draft.due_date}
                                onChange={(e) => setDraft((d) => ({ ...d, due_date: e.target.value }))}
                                className="text-primary w-full min-w-0 bg-transparent text-base outline-none md:text-sm"
                            />
                            {draft.due_date && (
                                <button
                                    type="button"
                                    onClick={() => setDraft((d) => ({ ...d, due_date: '' }))}
                                    className="text-muted-foreground hover:text-primary text-xs"
                                >
                                    {t('shopping.clear')}
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Delete lives here so it's reachable on touch (the row's trash is hover-only, desktop). */}
                    {!!editingId && (
                        <button
                            type="button"
                            onClick={() => {
                                const id = editingId;
                                removed.set(id, true);
                                setEditingId(null);
                                router.delete(route('shopping.items.destroy', id), {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onError: () => removed.clear(id),
                                });
                            }}
                            className="text-destructive flex items-center justify-center gap-2 py-1 text-sm font-medium"
                        >
                            <Trash2 className="size-4" /> {t('shopping.delete_item')}
                        </button>
                    )}
                </div>
            </ResponsiveModal>
        </AppLayout>
    );
}
