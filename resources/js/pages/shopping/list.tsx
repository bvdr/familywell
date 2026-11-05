import { useAddAction } from '@/lib/add-action';
import { Head, Link, router } from '@inertiajs/react';
import {
    Calendar,
    Check,
    ChevronLeft,
    ClipboardPaste,
    EyeOff,
    Loader2,
    Lock,
    Mic,
    Minus,
    MoreHorizontal,
    Plus,
    ShoppingBasket,
    Store,
    Trash2,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { syncThemeColor } from '@/hooks/use-appearance';
import { createPortal } from 'react-dom';

import { DuplicateWarning } from '@/components/duplicate-warning';
import { MemberAvatar } from '@/components/member-avatar';
import { Picker } from '@/components/picker';
import { SettingsSwitch } from '@/components/settings-ui';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { ListSettingsSheet, type ListDefaults, type ListPrefs } from '@/components/shopping/list-settings-sheet';
import { IOS, VoiceListening } from '@/components/shopping/voice-listening';
import { VoiceMicIntro } from '@/components/shopping/voice-mic-intro';
import { VoiceReview } from '@/components/shopping/voice-review';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useVoice, voiceSupported } from '@/hooks/use-voice';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { aisleIcon, bumpQuantity, getVoiceLang, normalizeItemName, setVoiceLang } from '@/lib/shopping';
import { newClientKey, PendingAdds, saveTick, send, useQueuedBought } from '@/lib/shopping-outbox';
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
type List = ListPrefs & { archived: boolean };

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

function AssigneeBadge({ member }: { member?: Member }) {
    if (!member) return null;
    return <MemberAvatar userId={member.id} name={member.name} className="size-6 text-[10px]" />;
}

type Parsed = { name: string; quantity: string; department: string; note: string; on_list?: boolean }; // on_list: server says an active item with this name is already on the list
// An aisle of the list's shop (custom per store, or the default Grocery set). `slug` is the value
// stored on an item's `department`; `icon` is a slug from the AISLE_ICONS pack.
type Aisle = { slug: string; name: string; icon: string };

// Minimal shape of the native SpeechRecognition API (not in the TS DOM lib; Chrome/Safari incl. iOS).
// isFinal: the browser won't change that part any more; the rest (interim) is its live guess, drawn dimmed.
type SpeechResultEvent = { results: ArrayLike<ArrayLike<{ transcript: string }> & { isFinal: boolean }> };
type SpeechRec = {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    onresult: (e: SpeechResultEvent) => void;
    onend: () => void;
    onerror: (e: { error: string }) => void;
    start: () => void;
    stop: () => void;
};
type SpeechCtor = new () => SpeechRec;

// The mic intro (design S3a) shows once per device: after "Allow microphone" the browser remembers the answer itself.
// try/catch: storage can throw (private mode); then the intro just shows again next time.
const MIC_INTRO_KEY = 'familywell:micIntroSeen';
const micIntroSeen = () => {
    try {
        return localStorage.getItem(MIC_INTRO_KEY) === '1';
    } catch {
        return false;
    }
};
const markMicIntroSeen = () => {
    try {
        localStorage.setItem(MIC_INTRO_KEY, '1');
    } catch {
        /* shows again next time */
    }
};

export default function ListDetail({
    list: serverList,
    items,
    members,
    aislesByStore,
    defaultAisles,
    share,
    stores,
    shops = [],
    shopLogos = {},
    defaults,
}: {
    list: List;
    items: Item[];
    members: Member[];
    aislesByStore: Record<string, Aisle[]>;
    defaultAisles: Aisle[];
    share: ShareState;
    stores: string[];
    shops?: string[];
    shopLogos?: Record<string, string | null>;
    defaults: ListDefaults;
}) {
    const t = useT();
    // Optimistic list fields: rename / visibility / edit-drawer saves render instantly by shadowing the
    // server `list` with the pending patch, so every existing `list.*` read below is optimistic for free.
    // Cleared on settle (success or error), at which point the reloaded prop is authoritative again.
    const listOpt = useOptimistic<Partial<List>>();
    const list: List = { ...serverList, ...listOpt.get(serverList.id, {}) };
    // The list's own Sort / Bought items (List settings, S2b), else the family's Shopping settings.
    const sort = list.sort ?? defaults.sort;
    // Hide bought (S2a) is remembered per list on this device; until it's touched it starts from Bought items.
    const hideKey = `shopping.hideBought.${list.id}`;
    const [hideBought, setHideBoughtState] = useState(() => {
        try {
            const v = localStorage.getItem(hideKey);
            if (v !== null) return v === '1';
        } catch {
            /* storage blocked (private mode): fall through to the list's setting */
        }
        return (list.bought ?? defaults.bought) === 'hide';
    });
    const setHideBought = (v: boolean) => {
        setHideBoughtState(v);
        try {
            localStorage.setItem(hideKey, v ? '1' : '0');
        } catch {
            /* not remembered, still applies for this visit */
        }
    };
    const [shopFilter, setShopFilter] = useState(''); // '' = All; else only what you can get at that shop
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [focus, setFocus] = useState(false); // full-screen "shopping mode" overlay
    const [focusStore, setFocusStore] = useState(''); // which shop the focus run is for
    const [sharing, setSharing] = useState(false);
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
    // The tail of `transcript` the browser may still rewrite (interim results): the listening screen dims it.
    const [interim, setInterim] = useState('');
    const [listening, setListening] = useState(false);
    // The recognizer's last error code (e.g. 'no-speech', 'audio-capture', 'network'), shown on the listening screen.
    // Before, every error but a refusal was swallowed, so a failing recognizer looked like a frozen "Listening" screen.
    const [voiceError, setVoiceError] = useState<string | null>(null);
    // "Type" on the listening screen: the same overlay with the textarea instead (what was said so far kept).
    const [typing, setTyping] = useState(false);
    // S3a over the listening screen: 'intro' before the first-ever listen, 'denied' after the mic was refused.
    const [micIntro, setMicIntro] = useState<'intro' | 'denied' | null>(null);
    // Dictation language: the Shopping settings choice (per device), also switchable from the listening screen's EN pill.
    const [voiceLang, setLang] = useState(getVoiceLang);
    // S3b "Pause for 2 seconds and I'll wrap up": reset on every result, fires Done after 2s without one.
    const silenceRef = useRef<ReturnType<typeof setTimeout>>(undefined);
    const [parsing, setParsing] = useState(false);
    const [parsed, setParsed] = useState<Parsed[]>([]);
    const recRef = useRef<{ stop: () => void } | null>(null);
    // Web Speech API is a native browser feature (Chrome/Safari incl. iOS); when absent we fall back to typing.
    // The phone's status bar takes <meta theme-color> (iOS and Android): paint it ink while the full-screen ink voice
    // screens (mic intro, listening) are up, so the top isn't a light strip above them; back to the page colour after.
    const inkScreen = voiceOpen && stage !== 'review' && (micIntro !== null || (stage === 'voice' && !typing));
    useEffect(() => {
        if (!inkScreen) return;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#121100'); // --color-ink
        return syncThemeColor;
    }, [inkScreen]);
    // iPhone records instead of using Web Speech (see the recorder below), so there it needs MediaRecorder.
    const speechSupported = IOS
        ? voiceSupported()
        : typeof window !== 'undefined' && ('SpeechRecognition' in window || 'webkitSpeechRecognition' in window);

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

    // The page (S2). Shop chips: every shop with something still to get (primary or alternative), with what's left
    // there; only when there's more than one. A chip whose shop ran out falls back to All.
    const shopCounts = focusStores
        .filter((s) => s && s !== FOCUS_ALL)
        .map((s) => ({ shop: s, count: focusUnbought.filter((i) => availableAt(i, s)).length }));
    const filterShop = shopCounts.some((c) => c.shop === shopFilter) ? shopFilter : '';
    const inView = liveItems.filter((i) => !filterShop || availableAt(i, filterShop));
    // Hide bought (S2a): ticked items leave the page, fully bought aisles go with them, a line at the end counts them.
    const hiddenCount = hideBought ? inView.filter((i) => isBought(i)).length : 0;
    const shown = hideBought ? inView.filter((i) => !isBought(i)) : inView;
    // Bought items sink to the bottom of their aisle. By aisle: one card per aisle in the shop's walk order (the chip's
    // shop, else the list's); A–Z and As added: one card. ponytail: All groups by the list shop's aisles, so an item
    // whose aisle only exists at another shop lands in Other there; pick that shop's chip to see its own aisles.
    const sinkBought = (xs: Item[]) => [...xs.filter((i) => !isBought(i)), ...xs.filter((i) => isBought(i))];
    const groups: { aisle: Aisle | null; items: Item[] }[] =
        sort === 'aisle'
            ? groupByAisle(shown, aislesFor(filterShop || list.store)).map((g) => ({ ...g, items: sinkBought(g.items) }))
            : shown.length
              ? [{ aisle: null, items: sinkBought(sort === 'az' ? [...shown].sort((a, b) => a.name.localeCompare(b.name)) : shown) }]
              : [];
    // An item's shop shows under its name only when it isn't the one the page is grouped by ("2 · Lidl").
    const pageShop = filterShop || list.store || '';
    const subLine = (i: Item) => [i.quantity, i.note, itemStore(i) !== pageShop && itemStore(i)].filter(Boolean).join(' · ');

    // "Bananas in the cart · Undo" after a tick (S2); a few seconds, or until the next tick.
    const [toast, setToast] = useState<Item | null>(null);
    useEffect(() => {
        if (!toast) return;
        const id = setTimeout(() => setToast(null), 4000);
        return () => clearTimeout(id);
    }, [toast]);

    // Sends the new value, not the bare toggle: a queued tick that replays (or a fast double tap) sets the
    // state instead of flipping it again. With no connection the tick waits in the outbox.
    const toggle = (item: Item) => {
        const next = !isBought(item);
        bought.set(item.id, next);
        setToast(next ? item : null);
        void saveTick({
            kind: 'bought',
            key: newClientKey(),
            method: 'patch',
            url: route('shopping.items.update', item.id),
            data: { bought: next },
            itemId: item.id,
        }).then(() => bought.clear(item.id));
    };
    const remove = (item: Item) => {
        removed.set(item.id, true);
        void save('delete', route('shopping.items.destroy', item.id)).then(() => removed.clear(item.id));
    };

    const patchList = (data: Partial<List>) => {
        listOpt.set(list.id, { ...listOpt.get(list.id, {}), ...data });
        void save('patch', route('shopping.lists.update', list.id), data).then(() => listOpt.clear(list.id));
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
    const patchItem = (id: number) => void save('patch', route('shopping.items.update', id), buildPayload(draft));
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
            {
                kind: 'add',
                key: data.client_key,
                method: 'post',
                url: route('shopping.items.store', list.id),
                data,
                page: window.location.pathname,
                name: data.name,
            },
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
        void save('patch', route('shopping.items.update', dupItem.id), { quantity: dupBump });
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
        setInterim('');
        setTyping(false);
        setMicIntro(null);
        setParsed([]);
        setMode(from);
        setStage(from);
        setVoiceOpen(true);
        if (from !== 'voice' || !speechSupported) return;
        // The first time on this device: the intro first; its Allow tap starts listening (and the system prompts).
        if (!micIntroSeen()) setMicIntro('intro');
        // Straight into listening (design S3b), inside this tap: iOS only starts recognition from a user gesture.
        else startListening(voiceLang);
    };
    // "Paste a list": same overlay + review + bulk-add as voice, just a textarea and the line-aware
    // server split (shopping.paste) instead of dictation.
    const openPaste = () => openVoice('paste');
    const stopListening = () => {
        clearTimeout(silenceRef.current);
        recRef.current?.stop();
        recRef.current = null;
        setListening(false);
    };
    const closeVoice = () => {
        stopListening();
        setVoiceOpen(false);
    };

    // iPhone/iPad: record and transcribe on the server (the "Just say it" recorder, Whisper) instead of Web Speech.
    // There webkitSpeechRecognition started, held the mic (orange dot) and never returned a word or an error, in the
    // Home Screen app at least (WebKit bug 225298: not available in Home Screen web apps) and with continuous mode
    // (reported deaf on iOS in Safari too). The recorder works in both. Words arrive every ~4s instead of live, and
    // Whisper detects the language itself. Free, but it needs AI points left (409 'out').
    const heardRef = useRef(''); // the latest words from the recorder (its callbacks outlive renders)
    const levelRef = useRef(0); // its loudness, read by the waveform every frame (no re-render per level)
    const lastLoudRef = useRef(0);
    const finishingRef = useRef(false);
    const recorder = useVoice(
        (text) => {
            heardRef.current = text;
            setTranscript(text);
        },
        (level) => {
            levelRef.current = level;
            const now = performance.now();
            if (level > 0.08) lastLoudRef.current = now;
            // The same "pause and I'll wrap up" as Web Speech's 2s silence timer: quiet for 2.5s after words came in.
            else if (heardRef.current.trim() && now - lastLoudRef.current > 2500) void finishRecording();
        },
    );
    // Stop, get the final words for the whole recording, then sort them (or open typing when nothing was said).
    const finishRecording = async () => {
        if (finishingRef.current) return;
        finishingRef.current = true;
        clearTimeout(silenceRef.current);
        setParsing(true); // the Done spinner while the last words are read
        await recorder.stop();
        recRef.current = null;
        setListening(false);
        finishingRef.current = false;
        setParsing(false);
        if (heardRef.current.trim()) void runParseRef.current(heardRef.current);
        else setTyping(true);
    };
    // A refused mic: the intro's denied state; no points left or the reader down: the message on the listening screen.
    useEffect(() => {
        if (!recorder.error) return;
        recRef.current = null;
        setListening(false);
        if (recorder.error === 'denied') setMicIntro('denied');
        else setVoiceError(recorder.error);
    }, [recorder.error]);

    // Start dictation in a specific language. The Web Speech API can't auto-detect the spoken language,
    // it needs a BCP-47 code up front, so the user picks via the chips (default Romanian, remembered).
    const startListening = (lang: string) => {
        if (IOS) {
            stopListening();
            setVoiceError(null);
            heardRef.current = '';
            lastLoudRef.current = performance.now();
            void recorder.start(); // inside the tap: getUserMedia and the AudioContext need the gesture
            recRef.current = { stop: recorder.cancel };
            setListening(true);
            return;
        }
        const w = window as unknown as { SpeechRecognition?: SpeechCtor; webkitSpeechRecognition?: SpeechCtor };
        const SR = w.SpeechRecognition ?? w.webkitSpeechRecognition;
        if (!SR) return;
        stopListening(); // Redo / a new language: one recognizer at a time
        setVoiceError(null);
        const rec = new SR();
        rec.continuous = true;
        rec.interimResults = true;
        rec.lang = lang;
        rec.onresult = (e: SpeechResultEvent) => {
            let done = '';
            let live = '';
            for (let i = 0; i < e.results.length; i++) {
                if (e.results[i].isFinal) done += e.results[i][0].transcript;
                else live += e.results[i][0].transcript;
            }
            setTranscript(done + live);
            setInterim(live);
            clearTimeout(silenceRef.current);
            // Through a ref: this closure is from the render that started listening, and runParse reads `mode`.
            silenceRef.current = setTimeout(() => runParseRef.current(done + live), 2000);
        };
        // Only the current recognizer may flip the state: a stopped one ends AFTER its replacement started.
        rec.onend = () => {
            if (recRef.current === rec) setListening(false);
        };
        // Mic or speech recognition refused (now or earlier): the intro's denied state, with the way back and Type.
        rec.onerror = (e) => {
            // 'aborted' is our own stop() (Redo, language change, close): not an error.
            if (recRef.current !== rec || e.error === 'aborted') return;
            stopListening();
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') setMicIntro('denied');
            else setVoiceError(e.error);
        };
        rec.start();
        recRef.current = rec;
        setListening(true);
    };
    // The review's "already on this list" row: the active item it matches (same normalized name as the server's check).
    const reviewDup = (it: Parsed) =>
        it.on_list ? liveItems.find((i) => !isBought(i) && normalizeItemName(i.name) === normalizeItemName(it.name)) : undefined;
    // From the review: Redo / "Add more by voice" go back to the input they came from, listening again (design S3 -> S3b).
    const backToInput = () => {
        setTranscript('');
        setInterim('');
        setTyping(false);
        setStage(mode);
        if (mode === 'voice' && speechSupported) startListening(voiceLang);
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

    // `said` lets the silence timer pass the text it heard (its closure's `transcript` is stale).
    const runParse = async (said: string = transcript) => {
        stopListening();
        const text = said.trim();
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
            // The server sends null for an empty quantity/note; Parsed (and the review's stepper) wants strings.
            const items: Parsed[] = res.ok
                ? ((await res.json()).items as Parsed[]).map((x) => ({ ...x, quantity: x.quantity ?? '', note: x.note ?? '' }))
                : naiveParse(text);
            setParsed((prev) => [...prev, ...items]);
        } catch {
            setParsed((prev) => [...prev, ...naiveParse(text)]);
        } finally {
            setParsing(false);
            setStage('review');
        }
    };
    const runParseRef = useRef(runParse);
    runParseRef.current = runParse;

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

    return (
        <AppLayout breadcrumbs={breadcrumbs} hideMobileHeader hideTabBar>
            <Head title={list.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 px-4 pt-4 pb-[calc(6.5rem+env(safe-area-inset-bottom))]">
                {/* Top bar (S2): back to Shopping, then share and List settings. Sticky like the settings pages' back
                    pill (no app top bar on phones), with a fade so rows slide under it softly. */}
                <div className="bg-background sticky top-0 z-20 -mx-4 -mt-4 flex items-center gap-2 px-4 pt-4 pb-1">
                    <Link
                        href="/m/shopping"
                        prefetch
                        className="bg-surface text-primary inline-flex min-h-11 items-center gap-1.5 rounded-full py-2 pr-4 pl-3 text-base font-semibold"
                    >
                        <ChevronLeft className="size-5" /> {t('nav.shopping')}
                    </Link>
                    <button
                        type="button"
                        onClick={() => setSharing(true)}
                        aria-label={t('shopping.share_list')}
                        className="bg-surface text-primary ml-auto flex size-11 items-center justify-center rounded-full"
                    >
                        <UserPlus className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => setSettingsOpen(true)}
                        aria-label={t('shopping.list_settings')}
                        className="bg-surface text-primary flex size-11 items-center justify-center rounded-full"
                    >
                        <MoreHorizontal className="size-5" />
                    </button>
                    <div
                        aria-hidden
                        className="from-background pointer-events-none absolute inset-x-0 top-full h-6 bg-gradient-to-b to-transparent"
                    />
                </div>

                <div className="flex flex-col gap-2">
                    <h1 className="font-display text-primary flex items-center gap-2 text-[34px] leading-[1.05] font-bold tracking-[-1.2px] text-balance">
                        <span className="min-w-0">{list.name}</span>
                        {list.visibility === 'private' && <Lock className="text-muted-foreground size-5 shrink-0" />}
                    </h1>
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-muted-foreground font-mono text-sm tabular-nums">
                            {t('shopping.n_of_m_bought', { bought: boughtCount, total: liveItems.length })}
                        </span>
                        <label className="text-primary flex items-center gap-2 text-sm">
                            {t('shopping.hide_bought')}
                            <SettingsSwitch on={hideBought} onToggle={() => setHideBought(!hideBought)} label={t('shopping.hide_bought')} />
                        </label>
                    </div>
                </div>

                {shopCounts.length > 1 && (
                    <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4">
                        {[{ shop: '', count: 0 }, ...shopCounts].map((c) => (
                            <button
                                key={c.shop || '__all__'}
                                type="button"
                                onClick={() => setShopFilter(c.shop)}
                                aria-pressed={filterShop === c.shop}
                                className={`min-h-10 shrink-0 rounded-full border px-4 text-base font-semibold whitespace-nowrap ${
                                    filterShop === c.shop ? 'bg-primary text-primary-foreground border-primary' : 'bg-surface-card text-primary'
                                }`}
                            >
                                {c.shop ? `${c.shop} · ${c.count}` : t('shopping.all')}
                            </button>
                        ))}
                    </div>
                )}

                {groups.map((group) => {
                    const AisleIcon = group.aisle ? aisleIcon(group.aisle.icon) : null;
                    return (
                        <section key={group.aisle?.slug ?? '__all__'} className="flex flex-col gap-2">
                            {group.aisle && AisleIcon && (
                                <h2 className="text-muted-foreground flex items-center gap-2 px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                                    <AisleIcon className="size-4" /> {group.aisle.name}
                                    <span className="ml-auto tabular-nums">{group.items.filter((i) => !isBought(i)).length}</span>
                                </h2>
                            )}
                            <div className="bg-surface-card divide-border flex flex-col divide-y overflow-hidden rounded-[22px] border">
                                {group.items.map((item) => {
                                    const done = isBought(item);
                                    const sub = subLine(item);
                                    return (
                                        <div key={item.id} className="group flex items-center gap-3 px-4 py-3">
                                            <button
                                                type="button"
                                                onClick={() => toggle(item)}
                                                aria-label={done ? t('shopping.mark_not_bought') : t('shopping.mark_bought')}
                                                className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                                                    done ? 'border-primary bg-primary text-accent-strong' : 'border-primary/70'
                                                }`}
                                            >
                                                {done && <Check className="size-4" strokeWidth={3} />}
                                            </button>
                                            <button type="button" onClick={() => openEdit(item)} className="min-w-0 flex-1 text-left">
                                                <span
                                                    className={`block truncate text-[17px] font-semibold ${done ? 'text-muted-foreground' : 'text-primary'}`}
                                                >
                                                    {item.name}
                                                </span>
                                                {sub && <span className="text-muted-foreground block truncate text-sm">{sub}</span>}
                                            </button>
                                            {item.assignedTo != null && <AssigneeBadge member={memberById.get(item.assignedTo)} />}
                                            {/* Desktop hover shortcut; on touch, delete is in the item sheet. */}
                                            <button
                                                type="button"
                                                onClick={() => remove(item)}
                                                aria-label={t('shopping.remove_item')}
                                                className="text-muted-foreground hover:text-destructive hidden size-7 items-center justify-center rounded-lg opacity-0 transition-opacity group-hover:opacity-100 md:flex"
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    );
                })}

                {hiddenCount > 0 && (
                    <p className="text-muted-foreground flex items-center justify-center gap-1.5 py-2 text-sm">
                        <EyeOff className="size-4" /> {t('shopping.n_hidden', { count: hiddenCount })} ·
                        <button type="button" onClick={() => setHideBought(false)} className="text-primary font-semibold">
                            {t('shopping.show')}
                        </button>
                    </p>
                )}

                {liveItems.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">{t('shopping.no_items_yet')}</p>}

                <PendingAdds />

                {/* Composer + Shop where the thumb is (S2): the page has no tab bar (Back is the way out), so it's fixed to the
                    bottom on every width, over the content column (right of the sidebar on desktop, like the
                    other bottom bars); the page's bottom padding keeps the last row clear. The undo toast sits on top. */}
                <div className="fixed inset-x-0 bottom-0 z-30 md:left-(--sidebar-width)">
                    <div className="mx-auto flex w-full max-w-2xl flex-col gap-3 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                        {toast && !focus && (
                            <div
                                role="status"
                                className="bg-primary text-primary-foreground flex items-center gap-3 rounded-2xl py-3 pr-3 pl-4 shadow-lg"
                            >
                                <span className="bg-accent-strong text-primary flex size-6 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-4" strokeWidth={3} />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-base font-semibold">
                                    {t('shopping.in_the_cart', { name: toast.name })}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => {
                                        const it = toast;
                                        setToast(null);
                                        if (isBought(it)) toggle(it);
                                    }}
                                    className="text-accent-strong min-h-10 px-2 text-base font-bold"
                                >
                                    {t('shopping.undo')}
                                </button>
                            </div>
                        )}
                        <div className="flex items-center gap-3">
                            <div className="bg-surface-card flex min-w-0 flex-1 items-center gap-1 rounded-full border py-1.5 pr-1.5 pl-4 shadow-[var(--shadow-fw-md)]">
                                <button
                                    type="button"
                                    onClick={openNew}
                                    className="text-muted-foreground flex min-h-10 min-w-0 flex-1 items-center gap-2 text-left text-base"
                                >
                                    <Plus className="text-primary size-5 shrink-0" />
                                    <span className="truncate">{t('shopping.add_item_placeholder')}</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={openPaste}
                                    aria-label={t('shopping.paste_list')}
                                    className="bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-full"
                                >
                                    <ClipboardPaste className="size-5" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => openVoice()}
                                    aria-label={t('shopping.add_by_voice')}
                                    className="bg-primary text-accent-strong flex size-10 shrink-0 items-center justify-center rounded-full"
                                >
                                    <Mic className="size-5" />
                                </button>
                            </div>
                            {liveItems.some((i) => !isBought(i)) && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        // Shop mode opens on the chip you're on, else the first shop with something to get.
                                        setFocusStore(filterShop || focusStores[0] || '');
                                        setFocus(true);
                                    }}
                                    aria-label={t('shopping.start_shopping')}
                                    className="bg-accent-strong text-accent-strong-foreground border-primary flex size-14 shrink-0 items-center justify-center rounded-full border-2 shadow-[var(--shadow-fw-md)]"
                                >
                                    <ShoppingBasket className="size-6" />
                                </button>
                            )}
                        </div>
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
                    <div
                        // The review is a sheet over the dimmed list (design S3); the other stages fill the screen.
                        className={`fixed inset-0 z-[60] flex flex-col ${stage === 'review' ? 'bg-ink/60' : 'bg-primary text-primary-foreground'}`}
                    >
                        {micIntro ? (
                            <VoiceMicIntro
                                denied={micIntro === 'denied'}
                                onAllow={() => {
                                    markMicIntroSeen();
                                    setMicIntro(null);
                                    startListening(voiceLang); // still inside the tap, as iOS requires
                                }}
                                onType={() => {
                                    setMicIntro(null);
                                    setTyping(true);
                                }}
                                onClose={closeVoice}
                            />
                        ) : stage === 'voice' && speechSupported && !typing ? (
                            <VoiceListening
                                listName={list.name}
                                finalText={transcript.slice(0, transcript.length - interim.length)}
                                interim={interim}
                                // Items heard so far, from the offline splitter on the settled text only (the AI sort
                                // runs on Done). Capitalised like list items.
                                chips={naiveParse(transcript.slice(0, transcript.length - interim.length)).map(
                                    (p) => p.name.charAt(0).toLocaleUpperCase() + p.name.slice(1),
                                )}
                                listening={listening}
                                parsing={parsing}
                                lang={voiceLang}
                                onLang={(code) => {
                                    setVoiceLang(code);
                                    setLang(code);
                                    startListening(code);
                                }}
                                onClose={closeVoice}
                                onStart={() => startListening(voiceLang)}
                                // Nothing heard yet: Done still does something useful, it stops and opens typing.
                                onDone={() => {
                                    if (IOS) return void finishRecording(); // its words may still be on the way
                                    if (transcript.trim()) return runParse();
                                    stopListening();
                                    setTyping(true);
                                }}
                                error={voiceError}
                                level={IOS ? levelRef : undefined}
                                onRedo={() => {
                                    setTranscript('');
                                    setInterim('');
                                    startListening(voiceLang);
                                }}
                                onType={() => {
                                    stopListening();
                                    setInterim('');
                                    setTyping(true);
                                }}
                            />
                        ) : stage !== 'review' ? (
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
                                    {/* Paste, Type (from the listening screen) and browsers without speech: a textarea. Listening
                                        itself is VoiceListening above. */}
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
                                </div>
                                <div className="px-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                                    <button
                                        type="button"
                                        onClick={() => runParse()}
                                        disabled={!transcript.trim() || parsing}
                                        className="bg-primary-foreground text-primary flex w-full items-center justify-center gap-2 rounded-xl py-3.5 font-medium disabled:opacity-50"
                                    >
                                        {parsing ? <Loader2 className="size-5 animate-spin" /> : <Check className="size-5" />}
                                        {parsing ? t('shopping.sorting') : t('shopping.done_button')}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <VoiceReview
                                heading={t(mode === 'paste' ? 'shopping.pasted_heading' : 'shopping.heard_heading')}
                                transcript={mode === 'voice' && transcript ? transcript : null}
                                groups={aislesFor(list.store)
                                    .map((a) => ({
                                        slug: a.slug,
                                        name: a.name,
                                        entries: parsed.map((it, idx) => ({ it, idx })).filter((e) => e.it.department === a.slug),
                                    }))
                                    .filter((g) => g.entries.length > 0)}
                                count={parsed.length}
                                paste={mode === 'paste'}
                                adding={addingAllState}
                                existing={(it) => {
                                    const cur = reviewDup(it);
                                    return cur ? { quantity: cur.quantity || null, bump: bumpQuantity(cur.quantity) } : null;
                                }}
                                onName={(idx, name) => setParsed((p) => p.map((x, i) => (i === idx ? { ...x, name } : x)))}
                                onQuantity={(idx, quantity) => setParsed((p) => p.map((x, i) => (i === idx ? { ...x, quantity } : x)))}
                                onRemove={(idx) => setParsed((p) => p.filter((_, i) => i !== idx))}
                                onBump={(idx) => {
                                    // One-tap fix (design S3): +1 on the row already on the list, and this one isn't added.
                                    const cur = reviewDup(parsed[idx]);
                                    const next = cur ? bumpQuantity(cur.quantity) : null;
                                    if (cur && next !== null) void save('patch', route('shopping.items.update', cur.id), { quantity: next });
                                    setParsed((p) => p.filter((_, i) => i !== idx));
                                }}
                                onClose={closeVoice}
                                onAddMore={backToInput}
                                onRedo={() => {
                                    setParsed([]);
                                    backToInput();
                                }}
                                onAdd={addAll}
                            />
                        )}
                    </div>,
                    document.body,
                )}

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}

            <ListSettingsSheet
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
                list={list}
                defaults={defaults}
                shops={shops.map((name) => ({ name, logo: shopLogos[name] ?? null }))}
                boughtCount={boughtCount}
                patch={(data) => patchList(data as Partial<List>)}
            />

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
                                void save('delete', route('shopping.items.destroy', id)).then(() => removed.clear(id));
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
