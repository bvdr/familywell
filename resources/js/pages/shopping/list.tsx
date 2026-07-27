import { Head, router } from '@inertiajs/react';
import {
    Archive,
    Check,
    ChevronLeft,
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
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { AisleEditor } from '@/components/aisle-editor';
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
import { aisleIcon, getVoiceLang } from '@/lib/shopping';
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
};
type List = { id: number; name: string; store: string | null; color: string; icon: string; archived: boolean; visibility: string };

type Draft = { name: string; quantity: string; department: string; assigned_to: number | null; note: string };
const emptyDraft: Draft = { name: '', quantity: '', department: 'other', assigned_to: null, note: '' };

function AssigneeBadge({ member }: { member?: Member }) {
    if (!member) return null;
    return (
        <span
            title={member.name}
            className="bg-accent-subtle text-primary flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
        >
            {member.name.charAt(0).toUpperCase()}
        </span>
    );
}

type Parsed = { name: string; quantity: string; department: string; note: string };
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
    list,
    items,
    members,
    aisles,
    share,
    stores,
    aiEnabled,
}: {
    list: List;
    items: Item[];
    members: Member[];
    aisles: Aisle[];
    share: ShareState;
    stores: string[];
    aiEnabled: boolean;
}) {
    const [hideCompleted, setHideCompleted] = useState(false);
    const [sortAz, setSortAz] = useState(false);
    const [focus, setFocus] = useState(false); // full-screen "shopping mode" overlay
    const [sharing, setSharing] = useState(false);
    const [editingTitle, setEditingTitle] = useState(false);
    const [title, setTitle] = useState(list.name);
    const [storeOpen, setStoreOpen] = useState(false);
    const [aislesOpen, setAislesOpen] = useState(false);
    const [storeDraft, setStoreDraft] = useState(list.store ?? '');
    const [editingId, setEditingId] = useState<number | null>(null); // null = closed; 0 = new item; >0 = editing
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const nameRef = useRef<HTMLInputElement>(null);

    // Voice / AI dictation flow.
    const [voiceOpen, setVoiceOpen] = useState(false);
    const [stage, setStage] = useState<'voice' | 'review'>('voice');
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
    const isBought = (i: Item) => bought.get(i.id, i.bought);
    const isGone = (i: Item) => removed.get(i.id, false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shopping', href: '/m/shopping' },
        { title: list.name, href: route('shopping.show', list.id) },
    ];

    const liveItems = items.filter((i) => !isGone(i));
    const boughtCount = liveItems.filter((i) => isBought(i)).length;
    const pct = liveItems.length ? Math.round((boughtCount / liveItems.length) * 100) : 0;

    const aisleBySlug = useMemo(() => new Map(aisles.map((a) => [a.slug, a])), [aisles]);
    // Group items into the shop's aisles (in walk order). Items whose department isn't a current
    // aisle (e.g. an aisle was deleted) fall into the shop's "other" aisle, or a trailing Other bucket.
    const groupByAisle = (list: Item[]): { aisle: Aisle; items: Item[] }[] => {
        const otherSlug = aisleBySlug.has('other') ? 'other' : '__other__';
        const buckets = new Map<string, Item[]>();
        for (const it of list) {
            const slug = aisleBySlug.has(it.department) ? it.department : otherSlug;
            (buckets.get(slug) ?? buckets.set(slug, []).get(slug)!).push(it);
        }
        const out = aisles.filter((a) => buckets.has(a.slug)).map((a) => ({ aisle: a, items: buckets.get(a.slug)! }));
        if (buckets.has('__other__')) out.push({ aisle: { slug: '__other__', name: 'Other', icon: 'basket' }, items: buckets.get('__other__')! });
        return out;
    };

    const groups = useMemo(() => {
        let visible = liveItems.filter((i) => (hideCompleted ? !isBought(i) : true));
        if (sortAz) visible = [...visible].sort((a, b) => a.name.localeCompare(b.name));
        return groupByAisle(visible);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, aisles, hideCompleted, sortAz, bought, removed]);

    // Focus mode splits the list into still-to-get (grouped by aisle) and an already-in-cart pile.
    const focusGroups = useMemo(
        () => groupByAisle(liveItems.filter((i) => !isBought(i))),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [items, aisles, bought, removed],
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const doneItems = useMemo(() => liveItems.filter((i) => isBought(i)), [items, bought, removed]);

    const toggle = (item: Item) => {
        bought.set(item.id, !isBought(item));
        router.patch(
            route('shopping.items.update', item.id),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => bought.clear(item.id), onError: () => bought.clear(item.id) },
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

    const patchList = (data: Record<string, string | number | boolean | null>) =>
        router.patch(route('shopping.lists.update', list.id), data, { preserveScroll: true });

    const saveTitle = () => {
        setEditingTitle(false);
        if (title.trim() && title !== list.name) patchList({ name: title.trim() });
    };

    const openNew = () => {
        setDraft(emptyDraft);
        setEditingId(0);
    };
    const openEdit = (item: Item) => {
        setDraft({
            name: item.name,
            quantity: item.quantity ?? '',
            department: item.department,
            assigned_to: item.assignedTo,
            note: item.note ?? '',
        });
        setEditingId(item.id);
    };
    const saveItem = () => {
        if (!draft.name.trim()) return;
        const payload = { ...draft, name: draft.name.trim() };
        const done = { preserveScroll: true, onSuccess: () => setEditingId(null) };
        if (editingId) router.patch(route('shopping.items.update', editingId), payload, done);
        else router.post(route('shopping.items.store', list.id), payload, done);
    };

    // Numeric stepper that no-ops on free-text quantities like "1 gallon".
    const bump = (delta: number) =>
        setDraft((d) => {
            const n = parseInt(d.quantity, 10);
            return { ...d, quantity: String(Math.max(0, (isNaN(n) ? 0 : n) + delta)) };
        });

    const openVoice = () => {
        setTranscript('');
        setParsed([]);
        setStage('voice');
        setVoiceOpen(true);
    };
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

    // Offline fallback: split a run-on list on commas / "and" / newlines. No aisle guessing (all 'other').
    const naiveParse = (text: string): Parsed[] =>
        text
            .split(/,|\band\b|\bthen\b|\n/i)
            .map((s) => s.replace(/^\s*(a few|some|a|an|the)\s+/i, '').trim())
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
            const res = await fetch(route('shopping.parse', list.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ transcript: text }),
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

    const addAll = () => {
        if (parsed.length === 0) return;
        router.post(route('shopping.items.bulk', list.id), { items: parsed }, { preserveScroll: true, onSuccess: closeVoice });
    };

    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label="Share list"
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="List options"
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setSharing(true)}>
                        <UserPlus className="size-4" /> Share list
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setTimeout(() => setEditingTitle(true), 0)}>
                        <Pencil className="size-4" /> Rename list
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setStoreOpen(true)}>
                        <Store className="size-4" /> Change store
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setAislesOpen(true)}>
                        <LayoutList className="size-4" /> Edit aisles
                    </DropdownMenuItem>
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <UnfoldVertical className="size-4" /> Sort items
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem onSelect={() => setSortAz(false)}>{!sortAz && <Check className="size-4" />} By aisle</DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setSortAz(true)}>{sortAz && <Check className="size-4" />} A to Z</DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuSeparator />
                    {list.visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'private' })}>
                            <Lock className="size-4" /> Make private
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'family' })}>
                            <Users className="size-4" /> Share with family
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => router.post(route('shopping.lists.duplicate', list.id))}>
                        <Copy className="size-4" /> Duplicate list
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => patchList({ archived: true })}>
                        <Archive className="size-4" /> Archive list
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm('Delete this list and all its items?')) router.delete(route('shopping.lists.destroy', list.id));
                        }}
                    >
                        <Trash2 className="size-4" /> Delete list
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={list.name} />
            <div className="mx-auto w-full max-w-2xl">
                <header className="bg-background/95 border-border/60 sticky top-0 z-20 border-b px-4 pt-3 pb-3 backdrop-blur-sm">
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
                            aria-label="List name"
                            className="font-heading text-primary border-border focus:border-accent w-full rounded-lg border bg-transparent px-1 text-2xl outline-none"
                        />
                    ) : (
                        <button type="button" onClick={() => setEditingTitle(true)} className="group flex items-center gap-2 text-left">
                            <h1 className="font-heading text-primary text-2xl">{list.name}</h1>
                            {list.visibility === 'private' && <Lock className="text-muted-foreground size-4" />}
                        </button>
                    )}
                    <div className="mt-1 flex items-center justify-between gap-3">
                        <div className="text-muted-foreground flex items-center gap-1 text-sm">
                            {list.store && (
                                <>
                                    <Store className="size-4" /> {list.store}
                                </>
                            )}
                        </div>
                        <div className="text-muted-foreground text-sm tabular-nums">
                            {boughtCount} / {liveItems.length} done
                        </div>
                    </div>
                    <div className="bg-surface mt-2 h-1.5 overflow-hidden rounded-full">
                        <div className="bg-primary h-full rounded-full transition-all" style={{ width: `${pct}%` }} />
                    </div>
                </header>

                <div className="flex flex-col gap-4 p-4">
                    <button
                        type="button"
                        role="switch"
                        aria-checked={hideCompleted}
                        onClick={() => setHideCompleted((v) => !v)}
                        className="text-muted-foreground flex items-center justify-between text-sm"
                    >
                        <span>Hide completed</span>
                        <span className={`relative h-6 w-10 rounded-full transition-colors ${hideCompleted ? 'bg-primary' : 'bg-border'}`}>
                            <span
                                className={`bg-background absolute top-0.5 size-5 rounded-full shadow transition-all ${hideCompleted ? 'left-[1.125rem]' : 'left-0.5'}`}
                            />
                        </span>
                    </button>

                    {groups.map((group) => {
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
                                            aria-label={isBought(item) ? 'Mark not bought' : 'Mark bought'}
                                            className={`flex size-6 shrink-0 items-center justify-center rounded-md border transition-colors ${
                                                isBought(item) ? 'border-primary bg-primary text-primary-foreground' : 'border-border'
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
                                        </button>
                                        {item.assignedTo != null && <AssigneeBadge member={memberById.get(item.assignedTo)} />}
                                        <button
                                            type="button"
                                            onClick={() => remove(item)}
                                            aria-label="Remove item"
                                            className="text-muted-foreground hover:text-destructive flex size-7 items-center justify-center rounded-lg opacity-0 transition-opacity group-hover:opacity-100"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                ))}
                            </section>
                        );
                    })}

                    {liveItems.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">No items yet. Add your first below.</p>}

                    <div className="sticky bottom-4 flex flex-col gap-2">
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={openNew} className="flex-1">
                                <Plus className="size-4" /> Add item
                            </Button>
                            <Button type="button" variant="outline" size="icon" onClick={openVoice} aria-label="Add by voice">
                                <Mic className="size-4" />
                            </Button>
                        </div>
                        {liveItems.some((i) => !isBought(i)) && (
                            <Button type="button" onClick={() => setFocus(true)} className="w-full shadow-[var(--shadow-fw-md)]">
                                <ShoppingCart className="size-4" /> Start Shopping
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
                        <div className="flex items-start justify-between gap-3 px-5 pt-[calc(1rem+env(safe-area-inset-top))] pb-4">
                            <div className="min-w-0">
                                <div className="text-primary-foreground/60 flex items-center gap-1.5 text-[11px] font-semibold tracking-widest uppercase">
                                    Focus Mode · Shopping
                                </div>
                                {list.store && (
                                    <div className="text-primary-foreground/80 mt-1 flex items-center gap-1.5 text-sm">
                                        <Store className="size-4" /> {list.store}
                                    </div>
                                )}
                                <h2 className="font-heading mt-1 truncate text-2xl">{list.name}</h2>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="text-primary-foreground/80 text-sm tabular-nums">
                                    {boughtCount}/{liveItems.length}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setFocus(false)}
                                    aria-label="Exit shopping mode"
                                    className="hover:bg-primary-foreground/10 flex size-9 items-center justify-center rounded-lg transition-colors"
                                >
                                    <X className="size-5" />
                                </button>
                            </div>
                        </div>

                        <div className="flex-1 space-y-6 overflow-y-auto px-5 pb-[calc(2rem+env(safe-area-inset-bottom))]">
                            {focusGroups.map((group) => {
                                const AisleIcon = aisleIcon(group.aisle.icon);
                                return (
                                    <section key={group.aisle.slug} className="space-y-2">
                                        <h3 className="text-primary-foreground/60 flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
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
                                                    {item.quantity && <div className="text-primary-foreground/60 text-sm">{item.quantity}</div>}
                                                </div>
                                            </button>
                                        ))}
                                    </section>
                                );
                            })}

                            {focusGroups.length === 0 && (
                                <p className="text-primary-foreground/80 py-10 text-center">Everything's in the cart. Nice work! 🎉</p>
                            )}

                            {doneItems.length > 0 && (
                                <section className="space-y-2">
                                    <h3 className="text-primary-foreground/50 text-xs font-semibold tracking-widest uppercase">Done</h3>
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
                        {stage === 'voice' ? (
                            <>
                                <div className="flex justify-end px-5 pt-[calc(1rem+env(safe-area-inset-top))]">
                                    <button
                                        type="button"
                                        onClick={closeVoice}
                                        aria-label="Close"
                                        className="hover:bg-primary-foreground/10 flex size-9 items-center justify-center rounded-lg"
                                    >
                                        <X className="size-5" />
                                    </button>
                                </div>
                                <div className="flex flex-1 flex-col items-center justify-center gap-6 px-6 text-center">
                                    {speechSupported && (
                                        <button
                                            type="button"
                                            onClick={toggleListening}
                                            aria-label={listening ? 'Stop' : 'Start listening'}
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
                                    {speechSupported ? (
                                        transcript ? (
                                            <p className="text-lg leading-relaxed">{transcript}</p>
                                        ) : (
                                            <p className="text-primary-foreground/70 text-sm">
                                                Just say everything you need, I'll sort it into your list.
                                            </p>
                                        )
                                    ) : (
                                        <>
                                            <p className="text-primary-foreground/70 text-sm">
                                                Type everything you need, I'll sort it into your list.
                                            </p>
                                            <textarea
                                                autoFocus
                                                value={transcript}
                                                onChange={(e) => setTranscript(e.target.value)}
                                                placeholder="milk, eggs, a few bananas for the kids..."
                                                className="border-primary-foreground/20 placeholder:text-primary-foreground/40 min-h-28 w-full rounded-xl border bg-transparent p-3 text-base outline-none"
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
                                        {parsing ? 'Sorting...' : 'Done'}
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
                                            setStage('voice');
                                        }}
                                        className="text-primary-foreground/80 flex items-center gap-1 text-sm"
                                    >
                                        <ChevronLeft className="size-4" /> Redo
                                    </button>
                                    {aiEnabled && (
                                        <span className="bg-primary-foreground/15 flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium">
                                            <Sparkles className="size-3.5" /> Organized by AI
                                        </span>
                                    )}
                                </div>
                                <div className="flex-1 space-y-4 overflow-y-auto px-5 pb-[calc(9rem+env(safe-area-inset-bottom))]">
                                    <div>
                                        <h2 className="font-heading text-2xl">Here's what I heard</h2>
                                        {transcript && <p className="text-primary-foreground/70 mt-2 text-sm italic">"{transcript}"</p>}
                                        <p className="text-primary-foreground/70 mt-2 text-sm">
                                            {parsed.length} item{parsed.length === 1 ? '' : 's'} sorted into aisles. Edit or remove any.
                                        </p>
                                    </div>
                                    {aisles
                                        .map((a) => ({
                                            aisle: a,
                                            entries: parsed.map((it, idx) => ({ it, idx })).filter((e) => e.it.department === a.slug),
                                        }))
                                        .filter((g) => g.entries.length > 0)
                                        .map((group) => {
                                            const AisleIcon = aisleIcon(group.aisle.icon);
                                            return (
                                                <section key={group.aisle.slug} className="space-y-2">
                                                    <h3 className="text-primary-foreground/60 flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                                                        <AisleIcon className="size-3.5" /> {group.aisle.name}
                                                    </h3>
                                                    {group.entries.map(({ it, idx }) => (
                                                        <div
                                                            key={idx}
                                                            className="border-primary-foreground/15 flex items-center gap-3 rounded-xl border p-3"
                                                        >
                                                            <div className="min-w-0 flex-1">
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
                                                                    placeholder="Quantity"
                                                                    className="text-primary-foreground/60 w-full bg-transparent text-sm outline-none"
                                                                />
                                                            </div>
                                                            <button
                                                                type="button"
                                                                onClick={() => setParsed((p) => p.filter((_, i) => i !== idx))}
                                                                aria-label="Remove"
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
                                        <p className="text-primary-foreground/70 py-8 text-center text-sm">Nothing left. Redo to try again.</p>
                                    )}
                                </div>
                                <div className="bg-primary absolute inset-x-0 bottom-0 space-y-2 px-5 pt-2 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setTranscript('');
                                            setStage('voice');
                                        }}
                                        className="border-primary-foreground/25 flex w-full items-center justify-center gap-2 rounded-xl border py-3 text-sm font-medium"
                                    >
                                        <Mic className="size-4" /> Add more by voice
                                    </button>
                                    <button
                                        type="button"
                                        onClick={addAll}
                                        disabled={parsed.length === 0}
                                        className="bg-primary-foreground text-primary flex w-full items-center justify-center gap-2 rounded-xl py-3.5 font-medium disabled:opacity-50"
                                    >
                                        <Check className="size-5" /> Add {parsed.length} item{parsed.length === 1 ? '' : 's'} to list
                                    </button>
                                </div>
                            </>
                        )}
                    </div>,
                    document.body,
                )}

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}

            <AisleEditor open={aislesOpen} onOpenChange={setAislesOpen} listId={list.id} storeName={list.store} />

            {/* Change store */}
            <ResponsiveModal
                open={storeOpen}
                onOpenChange={setStoreOpen}
                title="Change store"
                footer={
                    <Button
                        className="w-full"
                        onClick={() => {
                            patchList({ store: storeDraft.trim() || null });
                            setStoreOpen(false);
                        }}
                    >
                        Save
                    </Button>
                }
            >
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap gap-2">
                        {stores.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setStoreDraft(s)}
                                className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm ${
                                    storeDraft === s ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'
                                }`}
                            >
                                <Store className="size-3.5" /> {s}
                            </button>
                        ))}
                    </div>
                    <Input value={storeDraft} onChange={(e) => setStoreDraft(e.target.value)} placeholder="Store name" />
                </div>
            </ResponsiveModal>

            {/* Add / edit item */}
            <ResponsiveModal
                open={editingId !== null}
                onOpenChange={(v) => !v && setEditingId(null)}
                title={editingId ? 'Edit Item' : 'Add Item'}
                autoFocusRef={nameRef}
                footer={
                    <Button className="w-full" disabled={!draft.name.trim()} onClick={saveItem}>
                        {editingId ? 'Save' : 'Add item'}
                    </Button>
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Item</Label>
                        <Input
                            ref={nameRef}
                            value={draft.name}
                            onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
                            placeholder="Organic bananas"
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Quantity</Label>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => bump(-1)}
                                aria-label="Decrease"
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Minus className="size-4" />
                            </button>
                            <Input
                                value={draft.quantity}
                                onChange={(e) => setDraft((d) => ({ ...d, quantity: e.target.value }))}
                                placeholder="2 bunches"
                                className="text-center"
                            />
                            <button
                                type="button"
                                onClick={() => bump(1)}
                                aria-label="Increase"
                                className="border-border text-primary flex size-9 items-center justify-center rounded-lg border"
                            >
                                <Plus className="size-4" />
                            </button>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Department</Label>
                        <div className="flex flex-wrap gap-2">
                            {aisles.map((a) => {
                                const Icon = aisleIcon(a.icon);
                                const active = draft.department === a.slug;
                                return (
                                    <button
                                        key={a.slug}
                                        type="button"
                                        onClick={() => setDraft((dr) => ({ ...dr, department: a.slug }))}
                                        className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                            active ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'
                                        }`}
                                    >
                                        <Icon className="size-3.5" /> {a.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">For</Label>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setDraft((d) => ({ ...d, assigned_to: null }))}
                                className={`rounded-full border px-3 py-1.5 text-sm ${draft.assigned_to === null ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'}`}
                            >
                                Anyone
                            </button>
                            {members.map((m) => (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => setDraft((d) => ({ ...d, assigned_to: m.id }))}
                                    className={`rounded-full border px-3 py-1.5 text-sm ${draft.assigned_to === m.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'}`}
                                >
                                    {m.name}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Note</Label>
                        <Input
                            value={draft.note}
                            onChange={(e) => setDraft((d) => ({ ...d, note: e.target.value }))}
                            placeholder="Add a note (optional)"
                        />
                    </div>
                </div>
            </ResponsiveModal>
        </AppLayout>
    );
}
