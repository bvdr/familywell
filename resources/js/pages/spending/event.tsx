import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Banknote,
    BarChart3,
    Camera,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    CreditCard,
    Landmark,
    Link2,
    Loader2,
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
    UserPlus,
    Wallet,
    X,
} from 'lucide-react';
import { createContext, FormEventHandler, useContext, useEffect, useMemo, useRef, useState } from 'react';

import { GuestSheet, type GuestShareState } from '@/components/guest-sheet';
import InputError from '@/components/input-error';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { compressImage } from '@/lib/compress-image';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type EventT = {
    id: number;
    name: string;
    currency: string;
    total: number;
    people: number; // total headcount (sum of participant weights), not row count
    members: Member[];
    categories: string[];
    currencies: string[];
    rates: Record<string, number>; // base currency -> each supported currency (latest)
};
type Spend = {
    id: number;
    amount: number; // original amount in `currency`
    currency: string;
    base_amount: number; // converted to the event currency
    category: string;
    note: string | null;
    receipts: { id: number; url: string }[];
    source: Source | null;
    // A user paid (id set) or a guest did (isGuest, no user id to prefill the payer select with).
    payer: { id: number | null; name: string | null; isGuest: boolean };
    // How the spend splits; shares list the subset (some) or per-person amounts (exact, spend currency).
    split: 'all' | 'some' | 'exact';
    shares: { key: string; amount: number | null }[];
    spent_at: string;
};
type Source = { id: number; name: string; type: string };
const SOURCE_TYPE_META = [
    { key: 'cash', label: 'Cash', icon: Banknote },
    { key: 'card', label: 'Card', icon: CreditCard },
    { key: 'bank', label: 'Bank', icon: Landmark },
    { key: 'other', label: 'Other', icon: Wallet },
] as const;
// Participant ids are namespaced strings ('u{id}' for family members, 'g{id}' for guests) so users
// and guests never collide in one balance sheet.
type Balance = { id: string; name: string; net: number };
type Suggestion = { from: { id: string; name: string }; to: { id: string; name: string }; amount: number; canSettle: boolean };
type Participant = { key: string; name: string; kind: 'member' | 'guest'; weight: number; coveredBy: string | null };
type Transfer = { id: number; from: string; to: string; amount: number; date: string };
// Personal breakdown for the viewer (base cents). null when the viewer isn't a participant.
type MySummary = { paid: number; share: number; sent: number; received: number; net: number } | null;

// Soft, warm per-person avatar palette (borrowed from the reference). Colours are assigned by the
// participant's position (see AvatarColorContext) so everyone in an event gets a distinct colour;
// personColor() is only the fallback for a name that isn't a current participant.
const PERSON_COLORS = [
    { bg: '#DCE7D5', fg: '#3B5233' }, // sage
    { bg: '#D9E2EC', fg: '#3A4A5B' }, // slate blue
    { bg: '#EAD9DE', fg: '#6B4652' }, // dusty rose
    { bg: '#E7DECF', fg: '#5C513A' }, // sand
    { bg: '#E1DAE9', fg: '#4E4460' }, // muted violet
    { bg: '#E6DCCF', fg: '#6B4E3D' }, // clay
    { bg: '#D5E4E2', fg: '#33544F' }, // teal
    { bg: '#EDE0CC', fg: '#6B5A32' }, // wheat
    { bg: '#E3DCF0', fg: '#463C63' }, // lavender
    { bg: '#E9D7CE', fg: '#6E4A3A' }, // terracotta
    { bg: '#D8E3D0', fg: '#425235' }, // olive
    { bg: '#DBDFEA', fg: '#3D4657' }, // periwinkle
] as const;
const personColor = (name: string) => {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return PERSON_COLORS[h % PERSON_COLORS.length];
};
// Name -> colour for the current event's participants, so avatars are distinct (and stable) everywhere.
const AvatarColorContext = createContext<Map<string, { bg: string; fg: string }> | null>(null);
function PersonAvatar({ name, className = 'size-9 text-sm' }: { name: string; className?: string }) {
    const map = useContext(AvatarColorContext);
    const c = map?.get(name) ?? personColor(name);
    return (
        <span
            className={`flex shrink-0 items-center justify-center rounded-full font-semibold ${className}`}
            style={{ backgroundColor: c.bg, color: c.fg }}
        >
            {(name || '?').charAt(0).toUpperCase()}
        </span>
    );
}

const TABS = [
    { key: 'expenses', label: 'Expenses' },
    { key: 'transfers', label: 'Transfers' },
    { key: 'balances', label: 'Balances' },
    { key: 'settings', label: 'Settings' },
] as const;
type TabKey = (typeof TABS)[number]['key'];

const CATEGORY: Record<string, string> = {
    food: '🍽️',
    transport: '🚗',
    lodging: '🏨',
    activities: '🎟️',
    other: '•',
};

const today = () => new Date().toISOString().slice(0, 10);

// Relative day header for the spend log: Today / Yesterday / a dated label.
const dayLabel = (date: string) => {
    const d = new Date(date + 'T00:00:00');
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const diff = Math.round((now.getTime() - d.getTime()) / 86_400_000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
};

export default function Event({
    event,
    spends,
    sources,
    balances,
    suggestions,
    participants,
    transfers,
    mySummary,
    familyId,
    share,
    guestShare,
    scanEnabled,
}: {
    event: EventT;
    spends: Spend[];
    sources: Source[];
    balances: Balance[];
    suggestions: Suggestion[];
    participants: Participant[];
    transfers: Transfer[];
    mySummary: MySummary;
    familyId: number;
    share: ShareState;
    guestShare: GuestShareState;
    scanEnabled: boolean;
}) {
    const authId = (usePage().props as { auth: { user: { id: number } } }).auth.user.id;
    const [tab, setTab] = useState<TabKey>('expenses');
    const [adding, setAdding] = useState(false);
    const [sharing, setSharing] = useState(false);
    const [guestSharing, setGuestSharing] = useState(false);
    // When set, the form is editing this spend id instead of creating a new one.
    const [editing, setEditing] = useState<number | null>(null);
    // Receipt photos being viewed in the lightbox overlay, and which one is showing.
    const [viewing, setViewing] = useState<string[] | null>(null);
    const [viewIndex, setViewIndex] = useState(0);
    const openLightbox = (urls: string[], index = 0) => {
        setViewing(urls);
        setViewIndex(index);
    };
    // Display currency is cosmetic: it converts the base-currency figures for viewing. Default = base.
    const [display, setDisplay] = useState(event.currency);
    const fmt = (baseCents: number) => money(Math.round(baseCents * (event.rates[display] ?? 1)), display);

    // Inline title editing. `title` mirrors the server value (optimistic on save, re-synced on reload).
    const amountRef = useRef<HTMLInputElement>(null); // focused when the spend drawer opens, so the numeric pad shows
    const [editingTitle, setEditingTitle] = useState(false);
    const [title, setTitle] = useState(event.name);
    useEffect(() => setTitle(event.name), [event.name]);
    const saveTitle = () => {
        const name = title.trim();
        setEditingTitle(false);
        if (name && name !== event.name) {
            router.patch(route('spending.events.update', event.id), { name }, { preserveScroll: true });
        } else {
            setTitle(event.name); // ignore an empty or unchanged rename
        }
    };

    // Spends arrive newest-first, so a sequential pass groups them into contiguous days.
    const dayGroups = spends.reduce<{ date: string; spends: Spend[]; total: number }[]>((groups, s) => {
        const last = groups[groups.length - 1];
        if (last && last.date === s.spent_at) {
            last.spends.push(s);
            last.total += s.base_amount;
        } else {
            groups.push({ date: s.spent_at, spends: [s], total: s.base_amount });
        }
        return groups;
    }, []);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Spending', href: '/m/spending' },
        { title: event.name, href: route('spending.show', event.id) },
    ];

    // Live sync: a change from anyone in the family reloads this page's data. Best-effort.
    useEffect(() => {
        const echo = window.Echo;
        if (!echo) return;
        echo.private(`family.${familyId}`).listen('.SpendingChanged', () => router.reload());
        return () => echo.leave(`family.${familyId}`);
    }, [familyId]);

    const allKeys = participants.map((p) => p.key);
    const form = useForm<{
        amount: string;
        currency: string;
        category: string;
        payer_id: number;
        source_id: number | null;
        note: string;
        spent_at: string;
        receipts: File[];
        remove_receipt_ids: number[];
        split: 'all' | 'some' | 'exact';
        share_keys: string[];
        share_amounts: Record<string, string>;
    }>({
        amount: '',
        currency: event.currency,
        category: 'food',
        payer_id: authId,
        source_id: null,
        note: '',
        spent_at: today(),
        receipts: [],
        remove_receipt_ids: [],
        split: 'all',
        share_keys: allKeys,
        share_amounts: {},
    });

    // --- Per-expense split helpers (used by the Split section of the form) ---
    const toggleShareKey = (key: string) =>
        form.setData(
            'share_keys',
            form.data.share_keys.includes(key) ? form.data.share_keys.filter((k) => k !== key) : [...form.data.share_keys, key],
        );
    const setShareAmount = (key: string, val: string) =>
        form.setData('share_amounts', { ...form.data.share_amounts, [key]: val.replace(/,/g, '.').replace(/[^\d.]/g, '') });
    // Fill exact amounts by splitting the total equally (cents distributed to the first people).
    const splitEqually = () => {
        const total = Math.round(Number(form.data.amount || 0) * 100);
        const n = participants.length;
        if (n === 0) return;
        const base = Math.floor(total / n);
        const rem = total - base * n;
        const amounts: Record<string, string> = {};
        participants.forEach((p, i) => (amounts[p.key] = ((base + (i < rem ? 1 : 0)) / 100).toFixed(2)));
        form.setData('share_amounts', amounts);
    };
    const amountCents = Math.round(Number(form.data.amount || 0) * 100);
    const exactSumCents = participants.reduce((sum, p) => sum + Math.round(Number(form.data.share_amounts[p.key] || 0) * 100), 0);
    const remainingCents = amountCents - exactSumCents;
    const splitValid =
        form.data.split === 'all' ||
        (form.data.split === 'some' && form.data.share_keys.length > 0) ||
        (form.data.split === 'exact' && exactSumCents > 0 && remainingCents === 0);
    // Build the shares payload the backend expects from the split mode.
    const withShares = <T extends Record<string, unknown>>(d: T) => {
        const shares =
            form.data.split === 'some'
                ? form.data.share_keys.map((key) => ({ key }))
                : form.data.split === 'exact'
                  ? participants.map((p) => ({ key: p.key, amount: Number(form.data.share_amounts[p.key] || 0) }))
                  : [];
        return { ...d, split: form.data.split, shares };
    };

    // Inline "paid with" source creation. New sources appear in `sources` after the create reloads;
    // we then auto-select the freshest one.
    const [sourceModalOpen, setSourceModalOpen] = useState(false);
    const [newSource, setNewSource] = useState<{ name: string; type: string }>({ name: '', type: 'card' });
    const [savingSource, setSavingSource] = useState(false);
    const [justAddedSource, setJustAddedSource] = useState(false);
    const createSource = () => {
        if (!newSource.name.trim()) return;
        setSavingSource(true);
        router.post(route('spending.sources.store'), newSource, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setNewSource({ name: '', type: 'card' });
                setSourceModalOpen(false);
                setJustAddedSource(true);
            },
            onFinish: () => setSavingSource(false),
        });
    };
    useEffect(() => {
        if (justAddedSource && sources.length) {
            form.setData('source_id', sources[sources.length - 1].id);
            setJustAddedSource(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sources.length, justAddedSource]);

    // Existing receipts of the spend being edited, minus any the user has marked for removal.
    const existingReceipts = editing
        ? (spends.find((s) => s.id === editing)?.receipts ?? []).filter((r) => !form.data.remove_receipt_ids.includes(r.id))
        : [];

    const closeForm = () => {
        form.reset();
        setEditing(null);
        setAdding(false);
    };

    const submitSpend: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => closeForm() };
        if (editing) {
            // PHP never parses a multipart body on a PATCH request, so a receipt upload on edit must
            // go as POST + _method spoofing. forceFormData makes it multipart so _method is read as a
            // form field for the override.
            form.transform((d) => ({ ...withShares(d), _method: 'patch' }));
            form.post(route('spending.spends.update', editing), { ...opts, forceFormData: true });
        } else {
            form.transform((d) => withShares(d));
            form.post(route('spending.spends.store', event.id), opts);
        }
    };

    const startEdit = (s: Spend) => {
        form.setData({
            amount: (s.amount / 100).toString(),
            currency: s.currency,
            category: s.category,
            // Guest-paid spends have no user payer to prefill; default the select to the current user.
            payer_id: s.payer.id ?? authId,
            source_id: s.source?.id ?? null,
            note: s.note ?? '',
            spent_at: s.spent_at,
            receipts: [],
            remove_receipt_ids: [],
            split: s.split,
            share_keys: s.split === 'some' ? s.shares.map((x) => x.key) : allKeys,
            share_amounts: s.split === 'exact' ? Object.fromEntries(s.shares.map((x) => [x.key, String(x.amount ?? 0)])) : {},
        });
        setEditing(s.id);
        setAdding(true);
    };

    const startAdd = () => {
        form.reset();
        setEditing(null);
        setAdding(true);
    };

    // Scan a receipt photo: the endpoint reads it into prefill fields, then we open the New-spend drawer
    // pre-filled (and attach the photo as the receipt) so the user just reviews and Saves.
    const scanInputRef = useRef<HTMLInputElement>(null);
    const [scanning, setScanning] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);

    const handleScanFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = ''; // let the same photo be picked again after an error
        if (!file) return;
        setScanError(null);
        setScanning(true);
        try {
            const photo = await compressImage(file, 800_000);
            const body = new FormData();
            body.append('photo', photo);
            // Laravel validates the XSRF-TOKEN cookie via this header (no axios in this app).
            const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            const res = await fetch(route('spending.scan'), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                body,
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Could not read the receipt.');
            form.reset();
            form.setData({
                amount: data.amount != null ? String(data.amount) : '',
                currency: data.currency ?? event.currency,
                category: data.category ?? 'food',
                payer_id: authId,
                source_id: null,
                note: data.note ?? '',
                spent_at: data.date ?? today(),
                receipts: [photo], // keep the scanned photo attached to the spend
                remove_receipt_ids: [],
                split: 'all',
                share_keys: allKeys,
                share_amounts: {},
            });
            setEditing(null);
            setAdding(true);
        } catch (err) {
            setScanError(err instanceof Error ? err.message : 'Could not read the receipt.');
        } finally {
            setScanning(false);
        }
    };

    const duplicate = (s: Spend) => router.post(route('spending.spends.duplicate', s.id), {}, { preserveScroll: true });
    const remove = (s: Spend) => router.delete(route('spending.spends.destroy', s.id), { preserveScroll: true });

    // Settle-up records a user-to-user payment; the ids are 'u{id}' so strip the prefix. Suggestions
    // that involve a guest aren't one-click settleable (canSettle=false) and hide the button.
    const markPaid = (s: Suggestion) => {
        router.post(
            route('spending.settle', event.id),
            { from_user_id: Number(s.from.id.slice(1)), to_user_id: Number(s.to.id.slice(1)), amount: s.amount },
            { preserveScroll: true },
        );
    };

    // Split settings (Settings tab). Only the owner manages them; the server re-validates regardless.
    const canManageSettings = share?.canManage ?? false;
    const patchParticipant = (p: Participant, weight: number, coveredBy: string | null) =>
        router.patch(
            route('spending.participants.update', { event: event.id, key: p.key }),
            { weight, covered_by: coveredBy ?? '' },
            { preserveScroll: true, preserveState: true },
        );
    const setWeight = (p: Participant, w: number) => patchParticipant(p, Math.max(1, w), p.coveredBy);
    const setCover = (p: Participant, key: string | null) => patchParticipant(p, p.weight, key);

    // Distinct avatar colour per participant (by position), so no two people share a colour.
    const avatarColors = useMemo(() => {
        const m = new Map<string, { bg: string; fg: string }>();
        participants.forEach((p, i) => {
            if (!m.has(p.name)) m.set(p.name, PERSON_COLORS[i % PERSON_COLORS.length]);
        });
        return m;
    }, [participants]);

    // Record a transfer (settle-up payment) straight from the Transfers tab. Settlements are between
    // family members (they have accounts), so from/to are members; amounts are in the event's currency.
    const [recording, setRecording] = useState(false);
    const transferForm = useForm({ from_user_id: '', to_user_id: '', amount: '' });
    const transferValid =
        !!transferForm.data.from_user_id &&
        !!transferForm.data.to_user_id &&
        transferForm.data.from_user_id !== transferForm.data.to_user_id &&
        Number(transferForm.data.amount) > 0;
    const recordTransfer = () => {
        transferForm.transform((d) => ({
            from_user_id: Number(d.from_user_id),
            to_user_id: Number(d.to_user_id),
            amount: Math.round(Number(d.amount) * 100),
        }));
        transferForm.post(route('spending.settle', event.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRecording(false);
                transferForm.reset();
            },
        });
    };

    return (
        <AvatarColorContext.Provider value={avatarColors}>
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={event.name} />
                <div className="mx-auto w-full max-w-2xl">
                    {/* Sticky editorial header: the serif title, the display currency, and the tab bar that
                    stays put while a tab's content scrolls under it. */}
                    <header className="bg-background/95 border-border/60 sticky top-0 z-20 border-b px-4 pt-3 backdrop-blur-sm">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            {editingTitle ? (
                                <input
                                    autoFocus
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    onBlur={saveTitle}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') saveTitle();
                                        if (e.key === 'Escape') {
                                            setTitle(event.name);
                                            setEditingTitle(false);
                                        }
                                    }}
                                    aria-label="Event title"
                                    className="font-heading text-primary border-border focus:border-accent min-w-0 flex-1 rounded-lg border bg-transparent px-1 text-2xl outline-none"
                                />
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setEditingTitle(true)}
                                    className="group flex min-w-0 items-center gap-2 text-left"
                                    aria-label="Rename event"
                                >
                                    <h1 className="font-heading text-primary truncate text-2xl">{title}</h1>
                                    <Pencil className="text-muted-foreground group-hover:text-primary size-4 shrink-0 transition-colors" />
                                </button>
                            )}
                            <div className="flex shrink-0 items-center gap-2">
                                {/* Quick access to sharing; full controls also live in the Settings tab. */}
                                <button
                                    type="button"
                                    onClick={() => setSharing(true)}
                                    aria-label="Share with family"
                                    className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                                >
                                    <UserPlus className="size-4.5" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setGuestSharing(true)}
                                    aria-label="Guest link"
                                    className="border-border text-primary hover:bg-surface relative flex size-9 items-center justify-center rounded-lg border transition-colors"
                                >
                                    <Link2 className="size-4.5" />
                                    {guestShare?.enabled && <span className="bg-primary absolute -top-0.5 -right-0.5 size-2 rounded-full" />}
                                </button>
                                <Select value={display} onValueChange={setDisplay}>
                                    <SelectTrigger className="border-border text-primary h-9 w-[76px] shrink-0">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {event.currencies.map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {c}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <nav className="-mb-px flex gap-1 overflow-x-auto">
                            {TABS.map((t) => {
                                const active = tab === t.key;
                                return (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => setTab(t.key)}
                                        className={`relative shrink-0 px-3 pt-1 pb-2.5 text-sm font-medium transition-colors ${
                                            active ? 'text-primary' : 'text-muted-foreground hover:text-primary'
                                        }`}
                                    >
                                        {t.label}
                                        {t.key === 'transfers' && transfers.length > 0 && (
                                            <span className="text-muted-foreground ml-1 tabular-nums">{transfers.length}</span>
                                        )}
                                        {active && <span className="bg-primary absolute inset-x-3 -bottom-px h-0.5 rounded-full" />}
                                    </button>
                                );
                            })}
                        </nav>
                    </header>

                    <div className="flex flex-col gap-4 p-4">
                        {/* EXPENSES — total */}
                        {tab === 'expenses' && (
                            <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                                <div className="text-accent-light text-xs tracking-widest uppercase">Total spent</div>
                                <div className="font-heading mt-1 text-4xl">{fmt(event.total)}</div>
                                <div className="text-accent-light mt-1 text-sm">
                                    {event.people} {event.people === 1 ? 'person' : 'people'} · {spends.length}{' '}
                                    {spends.length === 1 ? 'spend' : 'spends'}
                                </div>
                            </div>
                        )}
                        {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={event.name} />}
                        {guestShare && (
                            <GuestSheet open={guestSharing} onOpenChange={setGuestSharing} guestShare={guestShare} entityName={event.name} />
                        )}

                        {/* New payment source — bottom drawer on mobile, centered overlay on desktop. */}
                        <ResponsiveModal
                            open={sourceModalOpen}
                            onOpenChange={setSourceModalOpen}
                            title="New payment source"
                            description="A reusable way to record how a spend was paid."
                        >
                            <div className="flex flex-col gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="source-name">Name</Label>
                                    <Input
                                        id="source-name"
                                        autoFocus
                                        value={newSource.name}
                                        onChange={(e) => setNewSource((s) => ({ ...s, name: e.target.value }))}
                                        onKeyDown={(e) => e.key === 'Enter' && createSource()}
                                        placeholder="Wise, cash Oana…"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Type</Label>
                                    <div className="grid grid-cols-4 gap-2">
                                        {SOURCE_TYPE_META.map((t) => {
                                            const active = newSource.type === t.key;
                                            return (
                                                <button
                                                    key={t.key}
                                                    type="button"
                                                    onClick={() => setNewSource((s) => ({ ...s, type: t.key }))}
                                                    className={`flex flex-col items-center gap-1.5 rounded-xl border p-2.5 text-xs font-medium transition-colors ${
                                                        active
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'bg-surface-card text-text-secondary hover:border-accent'
                                                    }`}
                                                >
                                                    <t.icon className="size-5" />
                                                    {t.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    onClick={createSource}
                                    disabled={!newSource.name.trim() || savingSource}
                                    className="mt-1 w-full"
                                >
                                    {savingSource ? 'Adding…' : 'Add source'}
                                </Button>
                            </div>
                        </ResponsiveModal>

                        {/* Record a payment (settle-up) — keyboard-safe drawer. From/to are members. */}
                        <ResponsiveModal
                            open={recording}
                            onOpenChange={(o) => !o && setRecording(false)}
                            title="Record a payment"
                            description="Money one member sends another to settle up. It counts in the balances."
                            footer={
                                <Button
                                    type="button"
                                    onClick={recordTransfer}
                                    disabled={transferForm.processing || !transferValid}
                                    className="w-full"
                                >
                                    Save
                                </Button>
                            }
                        >
                            <div className="flex flex-col gap-4 pt-1">
                                <div className="grid gap-2">
                                    <Label>From</Label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {event.members.map((m) => {
                                            const on = transferForm.data.from_user_id === String(m.id);
                                            return (
                                                <button
                                                    key={m.id}
                                                    type="button"
                                                    onClick={() => transferForm.setData('from_user_id', String(m.id))}
                                                    className={`flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                                                >
                                                    <PersonAvatar name={m.name} className="size-6 text-xs" />
                                                    <span className="text-primary min-w-0 flex-1 truncate">{m.name}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label>To</Label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {event.members
                                            .filter((m) => String(m.id) !== transferForm.data.from_user_id)
                                            .map((m) => {
                                                const on = transferForm.data.to_user_id === String(m.id);
                                                return (
                                                    <button
                                                        key={m.id}
                                                        type="button"
                                                        onClick={() => transferForm.setData('to_user_id', String(m.id))}
                                                        className={`flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                                                    >
                                                        <PersonAvatar name={m.name} className="size-6 text-xs" />
                                                        <span className="text-primary min-w-0 flex-1 truncate">{m.name}</span>
                                                    </button>
                                                );
                                            })}
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="transfer-amount">Amount ({event.currency})</Label>
                                    <Input
                                        id="transfer-amount"
                                        inputMode="decimal"
                                        value={transferForm.data.amount}
                                        onChange={(e) => transferForm.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                                        placeholder="0.00"
                                    />
                                    <InputError message={transferForm.errors.amount} />
                                </div>
                            </div>
                        </ResponsiveModal>

                        {/* TRANSFERS — recorded settle-up payments (+ record a new one) */}
                        {tab === 'transfers' && (
                            <section className="flex flex-col gap-2">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Transfers between you</h2>
                                    {event.members.length >= 2 && (
                                        <button
                                            type="button"
                                            onClick={() => setRecording(true)}
                                            className="text-primary flex items-center gap-1 text-sm font-medium"
                                        >
                                            <Plus className="size-4" /> Record
                                        </button>
                                    )}
                                </div>
                                {transfers.length === 0 && (
                                    <div className="bg-surface-card text-muted-foreground rounded-xl border p-6 text-center text-sm">
                                        No transfers yet.
                                        {event.members.length >= 2 && (
                                            <button
                                                type="button"
                                                onClick={() => setRecording(true)}
                                                className="text-primary ml-1 font-medium underline"
                                            >
                                                Record a payment
                                            </button>
                                        )}
                                    </div>
                                )}
                                {transfers.map((t) => (
                                    <div key={t.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                        <div className="flex shrink-0 items-center gap-1">
                                            <PersonAvatar name={t.from} className="size-8 text-xs" />
                                            <ArrowRight className="text-muted-foreground size-4" />
                                            <PersonAvatar name={t.to} className="size-8 text-xs" />
                                        </div>
                                        <div className="min-w-0 flex-1 text-sm">
                                            <div className="text-primary truncate font-medium">
                                                {t.from} → {t.to}
                                            </div>
                                            <div className="text-muted-foreground text-xs">{dayLabel(t.date)}</div>
                                        </div>
                                        <span className="text-primary font-mono text-sm font-semibold tabular-nums">{fmt(t.amount)}</span>
                                    </div>
                                ))}
                            </section>
                        )}

                        {/* BALANCES + suggested settle-up */}
                        {tab === 'balances' && (
                            <>
                                {/* Personal breakdown for the viewer. */}
                                {mySummary && (
                                    <section className="flex flex-col gap-2">
                                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Your summary</h2>
                                        <div className="bg-surface-card flex flex-col rounded-2xl border p-4">
                                            {[
                                                { label: 'Total spent', value: event.total },
                                                { label: 'You paid', value: mySummary.paid },
                                                { label: 'Your share', value: mySummary.share },
                                                ...(mySummary.sent > 0 ? [{ label: 'Transfers sent', value: mySummary.sent }] : []),
                                                ...(mySummary.received > 0 ? [{ label: 'Transfers received', value: mySummary.received }] : []),
                                            ].map((row) => (
                                                <div
                                                    key={row.label}
                                                    className="border-border/50 flex items-center justify-between border-b border-dashed py-2 text-sm"
                                                >
                                                    <span className="text-text-secondary">{row.label}</span>
                                                    <span className="text-primary font-mono tabular-nums">{fmt(row.value)}</span>
                                                </div>
                                            ))}
                                            <div className="mt-1 flex items-center justify-between pt-2">
                                                <span className="text-primary font-medium">
                                                    {mySummary.net > 0 ? 'You get back' : mySummary.net < 0 ? 'You owe' : 'You’re settled up'}
                                                </span>
                                                <span
                                                    className={`font-mono text-lg font-semibold tabular-nums ${mySummary.net > 0 ? 'text-primary' : mySummary.net < 0 ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}
                                                >
                                                    {mySummary.net === 0 ? '—' : fmt(Math.abs(mySummary.net))}
                                                </span>
                                            </div>
                                        </div>
                                    </section>
                                )}

                                <section className="flex flex-col gap-2">
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                        {mySummary ? 'Everyone' : 'Balances'}
                                    </h2>
                                    {balances.map((b) => (
                                        <div key={b.id} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                                            <div className="flex items-center gap-3">
                                                <PersonAvatar name={b.name} className="size-8 text-sm" />
                                                <div>
                                                    <div className="text-primary text-sm font-medium">{b.name}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {b.net > 0 ? 'gets back' : b.net < 0 ? 'owes' : 'settled up'}
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                className={`font-mono text-sm font-semibold tabular-nums ${b.net > 0 ? 'text-primary' : b.net < 0 ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}
                                            >
                                                {b.net > 0 ? '+' : ''}
                                                {fmt(b.net)}
                                            </div>
                                        </div>
                                    ))}
                                </section>

                                {suggestions.length > 0 && (
                                    <section className="flex flex-col gap-2">
                                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Suggested payments</h2>
                                        {suggestions.map((s, i) => (
                                            <div key={i} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                                                <div className="text-sm">
                                                    <span className="text-primary font-medium">{s.from.name}</span>
                                                    <span className="text-muted-foreground"> pays </span>
                                                    <span className="text-primary font-medium">{s.to.name}</span>
                                                    <span className="text-primary ml-2 font-mono font-semibold tabular-nums">{fmt(s.amount)}</span>
                                                </div>
                                                {s.canSettle && (
                                                    <Button size="sm" variant="outline" onClick={() => markPaid(s)}>
                                                        <Check className="size-4" /> Mark paid
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    </section>
                                )}
                            </>
                        )}

                        {/* SETTINGS — headcount, covered-by, sharing, stats */}
                        {tab === 'settings' && (
                            <section className="flex flex-col gap-5">
                                <div className="flex flex-col gap-2">
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">People &amp; split</h2>
                                    <p className="text-muted-foreground -mt-0.5 text-xs">
                                        Headcount is how many people each one pays for. "Covered by" moves someone's share onto another person.
                                    </p>
                                    {participants.map((p) => {
                                        const coversOthers = participants.some((o) => o.coveredBy === p.key);
                                        const coverName = p.coveredBy ? (participants.find((o) => o.key === p.coveredBy)?.name ?? '?') : null;
                                        return (
                                            <div key={p.key} className="bg-surface-card flex flex-col gap-3 rounded-xl border p-3">
                                                <div className="flex items-center gap-3">
                                                    <PersonAvatar name={p.name} />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="text-primary truncate text-sm font-medium">{p.name}</div>
                                                        <div className="text-muted-foreground truncate text-xs">
                                                            {p.kind === 'guest' ? 'Guest' : 'Member'}
                                                            {coverName && ` · covered by ${coverName}`}
                                                        </div>
                                                    </div>
                                                    {canManageSettings ? (
                                                        <div className="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                onClick={() => setWeight(p, p.weight - 1)}
                                                                disabled={p.weight <= 1}
                                                                aria-label={`Fewer people for ${p.name}`}
                                                                className="border-border text-primary flex size-8 items-center justify-center rounded-lg border text-lg leading-none disabled:opacity-40"
                                                            >
                                                                −
                                                            </button>
                                                            <span className="text-primary w-6 text-center text-sm font-semibold tabular-nums">
                                                                {p.weight}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => setWeight(p, p.weight + 1)}
                                                                aria-label={`More people for ${p.name}`}
                                                                className="border-border text-primary flex size-8 items-center justify-center rounded-lg border text-lg leading-none"
                                                            >
                                                                +
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground text-sm tabular-nums">×{p.weight}</span>
                                                    )}
                                                </div>
                                                {canManageSettings && participants.length > 1 && (
                                                    <div className="border-border/50 flex items-center justify-between gap-2 border-t pt-2.5">
                                                        <span className="text-muted-foreground text-xs">Pays</span>
                                                        <select
                                                            value={p.coveredBy ?? ''}
                                                            disabled={coversOthers}
                                                            onChange={(e) => setCover(p, e.target.value || null)}
                                                            className="border-border bg-surface-card text-primary rounded-lg border py-1.5 pr-7 pl-3 text-base disabled:opacity-50 md:text-sm"
                                                            title={coversOthers ? 'Covers someone else, so can’t be covered' : undefined}
                                                        >
                                                            <option value="">their own share</option>
                                                            {participants
                                                                .filter((o) => o.key !== p.key && !o.coveredBy)
                                                                .map((o) => (
                                                                    <option key={o.key} value={o.key}>
                                                                        covered by {o.name}
                                                                    </option>
                                                                ))}
                                                        </select>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>

                                <div className="flex flex-col gap-2">
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Event</h2>
                                    <button
                                        type="button"
                                        onClick={() => setSharing(true)}
                                        className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="text-primary flex items-center gap-2.5 font-medium">
                                            <UserPlus className="size-5" /> Share with family
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setGuestSharing(true)}
                                        className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="text-primary flex items-center gap-2.5 font-medium">
                                            <Link2 className="size-5" /> Guest link
                                            {guestShare?.enabled && <span className="bg-accent-light size-2 rounded-full" />}
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4" />
                                    </button>
                                    <Link
                                        href={route('spending.stats', event.id)}
                                        prefetch
                                        className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="text-primary flex items-center gap-2.5 font-medium">
                                            <BarChart3 className="size-5" /> See stats
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4" />
                                    </Link>
                                </div>
                            </section>
                        )}

                        {/* EXPENSES — add / scan. Scan is only offered when the server has a Gemini key configured. */}
                        {tab === 'expenses' && (
                            <div className="flex flex-col gap-2">
                                {scanEnabled && (
                                    <>
                                        <Button
                                            variant="outline"
                                            onClick={() => scanInputRef.current?.click()}
                                            disabled={scanning}
                                            className="w-full"
                                        >
                                            {scanning ? (
                                                <>
                                                    <Loader2 className="size-4 animate-spin" /> Reading receipt…
                                                </>
                                            ) : (
                                                <>
                                                    <Camera className="size-4" /> Scan receipt
                                                </>
                                            )}
                                        </Button>
                                        {/* capture=environment opens the rear camera on mobile; a normal picker on desktop. */}
                                        <input
                                            ref={scanInputRef}
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                            hidden
                                            onChange={handleScanFile}
                                        />
                                        {scanError && <p className="text-destructive text-center text-sm">{scanError}</p>}
                                    </>
                                )}
                                <Button onClick={startAdd} className="w-full">
                                    <Plus className="size-4" /> Add spend
                                </Button>
                            </div>
                        )}

                        {/* Add / edit a spend in a bottom drawer on mobile, a centered overlay on desktop.
                    Closing the drawer cancels. */}
                        <ResponsiveModal
                            open={adding}
                            onOpenChange={(o) => !o && closeForm()}
                            title={editing ? 'Edit spend' : 'New spend'}
                            className="sm:max-w-lg"
                            autoFocusRef={amountRef}
                            footer={
                                <Button type="submit" form="spend-form" disabled={form.processing || !splitValid} className="w-full">
                                    Save
                                </Button>
                            }
                        >
                            {/* The <form> holds no fields directly; the text inputs join it via form="spend-form" and
                        the dropdowns stay outside it. That keeps Radix's hidden native <select>s out of the
                        form's field set, so iOS enables the keyboard's prev/next arrows across Amount/Date/Note. */}
                            <form id="spend-form" onSubmit={submitSpend} className="hidden" />
                            <div className="flex flex-col gap-3">
                                {/* minmax(0,1fr) lets the amount input shrink below its intrinsic ~20ch width so the
                            row never overflows the drawer horizontally. */}
                                <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="amount">Amount</Label>
                                        {/* type=text (not number) so iOS honours inputMode=decimal and shows the real
                                    decimal pad. In comma-locales the separator key is a comma, so we normalise
                                    it to a dot (and strip stray chars) since the backend wants a plain number. */}
                                        <Input
                                            id="amount"
                                            form="spend-form"
                                            ref={amountRef}
                                            type="text"
                                            inputMode="decimal"
                                            value={form.data.amount}
                                            onChange={(e) => form.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                                            placeholder="0.00"
                                        />
                                        <InputError message={form.errors.amount} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>Currency</Label>
                                        <Select value={form.data.currency} onValueChange={(v) => form.setData('currency', v)}>
                                            <SelectTrigger className="w-[92px]">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {event.currencies.map((c) => (
                                                    <SelectItem key={c} value={c}>
                                                        {c}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="grid gap-2">
                                        <Label>Category</Label>
                                        <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                                            <SelectTrigger className="w-full capitalize">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {event.categories.map((c) => (
                                                    <SelectItem key={c} value={c} className="capitalize">
                                                        {CATEGORY[c]} {c}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>Paid by</Label>
                                        <Select value={String(form.data.payer_id)} onValueChange={(v) => form.setData('payer_id', Number(v))}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {event.members.map((m) => (
                                                    <SelectItem key={m.id} value={String(m.id)}>
                                                        {m.name}
                                                        {m.id === authId ? ' (You)' : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                {/* How this spend splits: everyone (default), a chosen subset, or exact amounts. */}
                                <div className="grid gap-2">
                                    <Label>Split</Label>
                                    <div className="bg-surface grid grid-cols-3 gap-1 rounded-lg border p-1">
                                        {(
                                            [
                                                ['all', 'Everyone'],
                                                ['some', 'Only some'],
                                                ['exact', 'Custom'],
                                            ] as const
                                        ).map(([k, l]) => (
                                            <button
                                                key={k}
                                                type="button"
                                                onClick={() => {
                                                    form.setData('split', k);
                                                    if (k === 'exact' && Object.keys(form.data.share_amounts).length === 0) splitEqually();
                                                }}
                                                className={`rounded-md py-1.5 text-sm font-medium transition-colors ${
                                                    form.data.split === k ? 'bg-primary text-primary-foreground' : 'text-text-secondary'
                                                }`}
                                            >
                                                {l}
                                            </button>
                                        ))}
                                    </div>

                                    {form.data.split === 'some' && (
                                        <div className="grid grid-cols-2 gap-2">
                                            {participants.map((p) => {
                                                const on = form.data.share_keys.includes(p.key);
                                                return (
                                                    <button
                                                        key={p.key}
                                                        type="button"
                                                        onClick={() => toggleShareKey(p.key)}
                                                        className={`flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition-colors ${
                                                            on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'
                                                        }`}
                                                    >
                                                        <PersonAvatar name={p.name} className="size-6 text-xs" />
                                                        <span className="text-primary min-w-0 flex-1 truncate">{p.name}</span>
                                                        {on && <Check className="text-primary size-4 shrink-0" />}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}

                                    {form.data.split === 'exact' && (
                                        <div className="flex flex-col gap-2">
                                            <div className="flex items-center justify-between">
                                                <span className="text-muted-foreground text-xs">Amount each owes ({form.data.currency})</span>
                                                <button type="button" onClick={splitEqually} className="text-primary text-xs font-medium underline">
                                                    Split equally
                                                </button>
                                            </div>
                                            {participants.map((p) => (
                                                <div key={p.key} className="flex items-center gap-2">
                                                    <PersonAvatar name={p.name} className="size-6 text-xs" />
                                                    <span className="text-primary min-w-0 flex-1 truncate text-sm">{p.name}</span>
                                                    <Input
                                                        form="spend-form"
                                                        inputMode="decimal"
                                                        value={form.data.share_amounts[p.key] ?? ''}
                                                        onChange={(e) => setShareAmount(p.key, e.target.value)}
                                                        placeholder="0.00"
                                                        className="w-24 text-right"
                                                    />
                                                </div>
                                            ))}
                                            <div
                                                className={`flex items-center justify-between text-xs ${remainingCents !== 0 ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}
                                            >
                                                <span>
                                                    {money(exactSumCents, form.data.currency)} of {money(amountCents, form.data.currency)}
                                                </span>
                                                <span>
                                                    {remainingCents === 0
                                                        ? 'balanced'
                                                        : `${money(Math.abs(remainingCents), form.data.currency)} ${remainingCents > 0 ? 'left' : 'over'}`}
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="grid gap-2">
                                    <Label>Paid with</Label>
                                    <Select
                                        value={form.data.source_id ? String(form.data.source_id) : 'none'}
                                        onValueChange={(v) => {
                                            // The last item opens the new-source modal instead of selecting a value.
                                            if (v === '__new__') setSourceModalOpen(true);
                                            else form.setData('source_id', v === 'none' ? null : Number(v));
                                        }}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Not specified</SelectItem>
                                            {sources.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name} <span className="text-muted-foreground">· {s.type}</span>
                                                </SelectItem>
                                            ))}
                                            <SelectItem value="__new__" className="text-primary font-medium">
                                                <span className="flex items-center gap-1.5">
                                                    <Plus className="size-3.5" /> New source
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="date">Date</Label>
                                    {/* appearance-none strips iOS's native date chrome so it keeps the shared h-10 sizing. */}
                                    <Input
                                        id="date"
                                        form="spend-form"
                                        type="date"
                                        className="appearance-none"
                                        value={form.data.spent_at}
                                        onChange={(e) => form.setData('spent_at', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="note">Note (optional)</Label>
                                    <Input
                                        id="note"
                                        form="spend-form"
                                        value={form.data.note}
                                        onChange={(e) => form.setData('note', e.target.value)}
                                        placeholder="Dinner at the taverna"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="receipts">Receipt photos (optional)</Label>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {/* Existing photos on the spend, each removable (queued for deletion on save). */}
                                        {existingReceipts.map((r) => (
                                            <div key={r.id} className="relative shrink-0">
                                                <img src={r.url} alt="Receipt" className="border-border size-14 rounded-md border object-cover" />
                                                <button
                                                    type="button"
                                                    onClick={() => form.setData('remove_receipt_ids', [...form.data.remove_receipt_ids, r.id])}
                                                    aria-label="Remove photo"
                                                    className="bg-background text-muted-foreground hover:text-destructive border-border absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full border shadow-sm"
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </div>
                                        ))}
                                        {/* Newly picked files (previewed locally), each removable before saving. */}
                                        {form.data.receipts.map((file, i) => (
                                            <div key={i} className="relative shrink-0">
                                                <img
                                                    src={URL.createObjectURL(file)}
                                                    alt="New photo"
                                                    className="border-border size-14 rounded-md border object-cover"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'receipts',
                                                            form.data.receipts.filter((_, k) => k !== i),
                                                        )
                                                    }
                                                    aria-label="Remove photo"
                                                    className="bg-background text-muted-foreground hover:text-destructive border-border absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full border shadow-sm"
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </div>
                                        ))}
                                        <label
                                            htmlFor="receipts"
                                            className="border-border text-muted-foreground hover:border-accent hover:text-primary flex size-14 shrink-0 cursor-pointer items-center justify-center rounded-md border border-dashed transition-colors"
                                        >
                                            <Plus className="size-5" />
                                        </label>
                                        <input
                                            id="receipts"
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            className="hidden"
                                            onChange={async (e) => {
                                                const files = Array.from(e.target.files ?? []);
                                                const compressed = await Promise.all(files.map((f) => compressImage(f)));
                                                form.setData('receipts', [...form.data.receipts, ...compressed]);
                                                e.target.value = ''; // let the same file be picked again after removal
                                            }}
                                        />
                                    </div>
                                    {form.progress && <progress value={form.progress.percentage} max="100" className="h-1 w-full" />}
                                    <InputError message={form.errors.receipts} />
                                </div>
                            </div>
                        </ResponsiveModal>

                        {/* EXPENSES — spend log, grouped by day, newest first, with a per-day subtotal */}
                        {tab === 'expenses' && (
                            <section className="flex flex-col gap-4">
                                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Spend log</h2>
                                {dayGroups.map((group) => (
                                    <div key={group.date} className="flex flex-col gap-2">
                                        <div className="flex items-center justify-between px-1">
                                            <span className="text-text-secondary text-sm font-medium">{dayLabel(group.date)}</span>
                                            <span className="text-muted-foreground font-mono text-sm tabular-nums">{fmt(group.total)}</span>
                                        </div>
                                        {group.spends.map((s) => (
                                            // Tapping the row edits the spend; the receipt thumbnail and the kebab
                                            // menu stop propagation so they keep their own actions.
                                            <div
                                                key={s.id}
                                                role="button"
                                                tabIndex={0}
                                                onClick={() => startEdit(s)}
                                                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && startEdit(s)}
                                                className="bg-surface-card hover:border-accent/60 flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                                            >
                                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                                    {s.receipts.length > 0 ? (
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                openLightbox(s.receipts.map((r) => r.url));
                                                            }}
                                                            className="relative shrink-0"
                                                            aria-label={`View ${s.receipts.length} receipt photo${s.receipts.length > 1 ? 's' : ''}`}
                                                        >
                                                            <img
                                                                src={s.receipts[0].url}
                                                                alt="Receipt"
                                                                className="border-border size-9 rounded-lg border object-cover"
                                                            />
                                                            {s.receipts.length > 1 && (
                                                                <span className="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold tabular-nums">
                                                                    {s.receipts.length}
                                                                </span>
                                                            )}
                                                        </button>
                                                    ) : (
                                                        <PersonAvatar name={s.payer.name ?? 'Guest'} />
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || s.category}</div>
                                                        <div className="text-muted-foreground truncate text-xs capitalize">
                                                            {(s.payer.name ?? 'Guest').split(' ')[0]} · {s.category}
                                                            {s.split !== 'all' && (
                                                                <span className="normal-case">
                                                                    {' '}
                                                                    · {s.split === 'exact' ? 'custom split' : `${s.shares.length} people`}
                                                                </span>
                                                            )}
                                                            {s.source && <span className="normal-case"> · {s.source.name}</span>}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1">
                                                    <div className="text-right">
                                                        <div className="text-primary font-mono text-sm font-semibold tabular-nums">
                                                            {money(s.amount, s.currency)}
                                                        </div>
                                                        {s.currency !== display && (
                                                            <div className="text-muted-foreground font-mono text-xs tabular-nums">
                                                                {fmt(s.base_amount)}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="text-muted-foreground size-8"
                                                                aria-label="Spend actions"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <MoreVertical className="size-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onClick={() => startEdit(s)}>
                                                                <Pencil className="size-4" /> Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem onClick={() => duplicate(s)}>
                                                                <Copy className="size-4" /> Duplicate
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                className="text-destructive focus:text-destructive"
                                                                onClick={() => remove(s)}
                                                            >
                                                                <Trash2 className="size-4" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ))}
                            </section>
                        )}
                    </div>
                </div>

                {/* Receipt lightbox — swipe/click through a spend's photos */}
                <Dialog open={!!viewing} onOpenChange={(open) => !open && setViewing(null)}>
                    <DialogContent className="max-w-3xl border-none bg-transparent p-0 shadow-none">
                        <DialogTitle className="sr-only">Receipt photos</DialogTitle>
                        {viewing && (
                            <div className="relative">
                                <img src={viewing[viewIndex]} alt="Receipt" className="max-h-[85vh] w-full rounded-lg object-contain" />
                                {viewing.length > 1 && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setViewIndex((i) => (i - 1 + viewing.length) % viewing.length)}
                                            aria-label="Previous photo"
                                            className="absolute top-1/2 left-2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm"
                                        >
                                            <ChevronLeft className="size-6" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setViewIndex((i) => (i + 1) % viewing.length)}
                                            aria-label="Next photo"
                                            className="absolute top-1/2 right-2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm"
                                        >
                                            <ChevronRight className="size-6" />
                                        </button>
                                        <span className="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-black/45 px-2.5 py-1 font-mono text-xs text-white tabular-nums">
                                            {viewIndex + 1} / {viewing.length}
                                        </span>
                                    </>
                                )}
                            </div>
                        )}
                    </DialogContent>
                </Dialog>
            </AppLayout>
        </AvatarColorContext.Provider>
    );
}
