import { useAddAction } from '@/lib/add-action';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowRight,
    Banknote,
    BarChart3,
    Camera,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Copy,
    CreditCard,
    Landmark,
    Link2,
    Loader2,
    MoreHorizontal,
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
import { useMemberColors } from '@/components/member-avatar';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { avatarColorFor } from '@/lib/avatar';
import { categoryIcon } from '@/lib/categories';
import { compressImage, makeThumbnail } from '@/lib/compress-image';
import { useT, type TFn } from '@/lib/i18n';
import { money, selectAllOnFocus } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type EventT = {
    id: number;
    name: string;
    currency: string;
    total: number;
    archived: boolean;
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
    receipts: { id: number; url: string; thumbUrl: string }[];
    source: Source | null;
    // Namespaced payer key ('u{id}' member / 'g{id}' guest) prefills the select; id/name/isGuest are for display.
    payer: { key: string; id: number | null; name: string | null; isGuest: boolean };
    // How the spend splits; shares list the subset (some) or per-person amounts (exact, spend currency).
    split: 'all' | 'some' | 'exact';
    shares: { key: string; amount: number | null }[];
    spent_at: string;
};
type Source = { id: number; name: string; type: string };
// labelKey resolves via t() at render time (this is a module-level const, so it can't call the hook).
const SOURCE_TYPE_META = [
    { key: 'cash', labelKey: 'spending.source_cash', icon: Banknote },
    { key: 'card', labelKey: 'spending.source_card', icon: CreditCard },
    { key: 'bank', labelKey: 'spending.source_bank', icon: Landmark },
    { key: 'other', labelKey: 'spending.source_other', icon: Wallet },
] as const;
const sourceTypeLabel = (t: TFn, type: string) => t(SOURCE_TYPE_META.find((s) => s.key === type)?.labelKey ?? 'spending.source_other');
// Participant ids are namespaced strings ('u{id}' for family members, 'g{id}' for guests) so users
// and guests never collide in one balance sheet.
type Balance = { id: string; name: string; net: number };
type Suggestion = { from: { id: string; name: string }; to: { id: string; name: string }; amount: number };
type Participant = { key: string; name: string; kind: 'member' | 'guest'; weight: number; coveredBy: string | null };
type Transfer = { id: number; from: string; to: string; amount: number; date: string; note: string | null };
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

// labelKey resolves via t() at render time (this is a module-level const, so it can't call the hook).
const TABS = [
    { key: 'expenses', labelKey: 'spending.tab_expenses' },
    { key: 'transfers', labelKey: 'spending.tab_transfers' },
    { key: 'balances', labelKey: 'spending.tab_balances' },
    { key: 'settings', labelKey: 'spending.tab_settings' },
] as const;
type TabKey = (typeof TABS)[number]['key'];

const today = () => new Date().toISOString().slice(0, 10);

// Relative day header for the spend log: Today / Yesterday / a dated label.
const dayLabel = (t: TFn, date: string) => {
    const d = new Date(date + 'T00:00:00');
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const diff = Math.round((now.getTime() - d.getTime()) / 86_400_000);
    if (diff === 0) return t('dashboard.today');
    if (diff === 1) return t('spending.yesterday');
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
    moveTargets = [],
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
    moveTargets?: { id: number; name: string; currency: string }[];
}) {
    const authId = (usePage().props as { auth: { user: { id: number } } }).auth.user.id;
    const t = useT();
    const [tab, setTab] = useState<TabKey>('expenses');
    const [adding, setAdding] = useState(false);
    useAddAction(() => setAdding(true));
    const [sharing, setSharing] = useState(false);
    const [guestSharing, setGuestSharing] = useState(false);
    // When set, the form is editing this spend id instead of creating a new one.
    const [editing, setEditing] = useState<number | null>(null);
    // "Advanced" section in the edit form + the "move to another list" flow (chosen target id, or '').
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [moveTarget, setMoveTarget] = useState<string>('');
    const editingSpend = editing ? spends.find((s) => s.id === editing) : undefined;
    const doMove = () => {
        if (!editing || !moveTarget) return;
        // Server redirects to the target list on success; resets split + re-converts the amount there.
        router.post(route('spending.spends.move', editing), { target_event_id: Number(moveTarget) }, { preserveScroll: true });
    };
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

    // Optimistically removed spends: the row vanishes on tap, before the server confirms the delete.
    const removedSpends = useOptimistic<boolean>();

    // Spends arrive newest-first, so a sequential pass groups them into contiguous days. Optimistically
    // deleted rows are filtered out first so they disappear immediately (per-day subtotals and the
    // header count follow); server-computed aggregates (event.total, balances) reconcile on reload.
    const visibleSpends = spends.filter((s) => !removedSpends.get(s.id, false));
    const dayGroups = visibleSpends.reduce<{ date: string; spends: Spend[]; total: number }[]>((groups, s) => {
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
        { title: t('nav.spending'), href: '/m/spending' },
        { title: event.name, href: route('spending.show', event.id) },
    ];

    // Share + guest-link controls live in the global header (right of the breadcrumbs) for every entity.
    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label={t('spending.share_with_family')}
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <button
                type="button"
                onClick={() => setGuestSharing(true)}
                aria-label={t('spending.guest_link')}
                className="border-border text-primary hover:bg-surface relative flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <Link2 className="size-4.5" />
                {guestShare?.enabled && <span className="bg-accent-strong absolute -top-0.5 -right-0.5 size-2 rounded-full" />}
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={t('spending.event_options')}
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setTimeout(() => setEditingTitle(true), 0)}>
                        <Pencil className="size-4" /> {t('spending.rename_event')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() =>
                            router.patch(route('spending.events.update', event.id), { archived: !event.archived }, { preserveScroll: true })
                        }
                    >
                        <Archive className="size-4" /> {t(event.archived ? 'spending.unarchive_event' : 'spending.archive_event')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

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
        payer_key: string;
        source_id: number | null;
        note: string;
        spent_at: string;
        receipts: File[];
        receipts_thumb: File[];
        remove_receipt_ids: number[];
        split: 'all' | 'some' | 'exact';
        share_keys: string[];
        share_amounts: Record<string, string>;
    }>({
        amount: '',
        currency: event.currency,
        category: 'food',
        payer_key: 'u' + authId,
        source_id: null,
        note: '',
        spent_at: today(),
        receipts: [],
        receipts_thumb: [],
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
    // Keys the user typed into by hand. Their values are pinned; everyone else's
    // share is auto-recomputed so the leftover always splits equally among the untouched.
    const [lockedKeys, setLockedKeys] = useState<string[]>([]);
    const setShareAmount = (key: string, val: string) => {
        const clean = val.replace(/,/g, '.').replace(/[^\d.]/g, '');
        const locked = lockedKeys.includes(key) ? lockedKeys : [...lockedKeys, key];
        const amounts: Record<string, string> = { ...form.data.share_amounts, [key]: clean };
        const total = Math.round(Number(form.data.amount || 0) * 100);
        const lockedCents = locked.reduce((s, k) => s + Math.round(Number(amounts[k] || 0) * 100), 0);
        const rest = participants.filter((p) => !locked.includes(p.key));
        if (rest.length > 0) {
            const leftover = Math.max(0, total - lockedCents); // overspent -> untouched owe 0, remaining shows "over"
            const base = Math.floor(leftover / rest.length);
            const rem = leftover - base * rest.length;
            rest.forEach((p, i) => (amounts[p.key] = ((base + (i < rem ? 1 : 0)) / 100).toFixed(2)));
        }
        setLockedKeys(locked);
        form.setData('share_amounts', amounts);
    };
    // Fill exact amounts by splitting the total equally (cents distributed to the first people).
    const splitEqually = () => {
        const total = Math.round(Number(form.data.amount || 0) * 100);
        const n = participants.length;
        if (n === 0) return;
        const base = Math.floor(total / n);
        const rem = total - base * n;
        const amounts: Record<string, string> = {};
        participants.forEach((p, i) => (amounts[p.key] = ((base + (i < rem ? 1 : 0)) / 100).toFixed(2)));
        setLockedKeys([]); // back to a clean equal split; nothing pinned
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
        setAdvancedOpen(false);
        setMoveTarget('');
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
            payer_key: s.payer.key,
            source_id: s.source?.id ?? null,
            note: s.note ?? '',
            spent_at: s.spent_at,
            receipts: [],
            receipts_thumb: [],
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
            const photoThumb = await makeThumbnail(file, 640);
            const body = new FormData();
            body.append('photo', photo);
            // Laravel validates the XSRF-TOKEN cookie via this header (no axios in this app).
            const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            // Relative URL: same-origin fetch must target the origin the user is on so the session cookie is sent.
            const res = await fetch(route('spending.scan', undefined, false), {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                body,
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || t('spending.scan_error_default'));
            form.reset();
            form.setData({
                amount: data.amount != null ? String(data.amount) : '',
                currency: data.currency ?? event.currency,
                category: data.category ?? 'food',
                payer_key: 'u' + authId,
                source_id: null,
                note: data.note ?? '',
                spent_at: data.date ?? today(),
                receipts: [photo], // keep the scanned photo attached to the spend
                receipts_thumb: photoThumb ? [photoThumb] : [],
                remove_receipt_ids: [],
                split: 'all',
                share_keys: allKeys,
                share_amounts: {},
            });
            setEditing(null);
            setAdding(true);
        } catch (err) {
            setScanError(err instanceof Error ? err.message : t('spending.scan_error_default'));
        } finally {
            setScanning(false);
        }
    };

    const duplicate = (s: Spend) => router.post(route('spending.spends.duplicate', s.id), {}, { preserveScroll: true });
    const remove = (s: Spend) => {
        removedSpends.set(s.id, true); // hide the row now; server response reconciles totals/balances
        router.delete(route('spending.spends.destroy', s.id), {
            preserveScroll: true,
            preserveState: true, // keep the override alive across the prop reload the delete triggers
            onError: () => removedSpends.clear(s.id),
        });
    };

    // Settle-up records a payment between two participants (members or guests). Suggestion ids are
    // already namespaced keys, so they pass straight through. One-click has no note.
    const markPaid = (s: Suggestion) => {
        router.post(route('spending.settle', event.id), { from_key: s.from.id, to_key: s.to.id, amount: s.amount }, { preserveScroll: true });
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

    // Avatar colour per participant. Family members use their global family colour (same everywhere in
    // the app); guests, who aren't family members, get a distinct colour from the warm fallback palette.
    const memberColorsMap = useMemberColors();
    const avatarColors = useMemo(() => {
        const m = new Map<string, { bg: string; fg: string }>();
        let guestI = 0;
        participants.forEach((p) => {
            if (m.has(p.name)) return;
            if (p.kind === 'member') {
                const [bg, fg] = avatarColorFor(memberColorsMap, Number(p.key.slice(1)), p.name);
                m.set(p.name, { bg, fg });
            } else {
                m.set(p.name, PERSON_COLORS[guestI++ % PERSON_COLORS.length]);
            }
        });
        return m;
    }, [participants, memberColorsMap]);

    // Record a transfer (settle-up payment) straight from the Transfers tab. from/to are participant
    // keys (members or guests); amounts are in the event's currency. Optional note shown in the list.
    const [recording, setRecording] = useState(false);
    const transferForm = useForm({ from_key: '', to_key: '', amount: '', note: '' });
    const transferValid =
        !!transferForm.data.from_key &&
        !!transferForm.data.to_key &&
        transferForm.data.from_key !== transferForm.data.to_key &&
        Number(transferForm.data.amount) > 0;
    const recordTransfer = () => {
        transferForm.transform((d) => ({
            from_key: d.from_key,
            to_key: d.to_key,
            amount: Math.round(Number(d.amount) * 100),
            note: d.note,
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
            <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
                <Head title={event.name} />
                <div className="mx-auto w-full max-w-2xl">
                    {/* Sticky editorial header: the serif title, the display currency, and the tab bar that
                    stays put while a tab's content scrolls under it. */}
                    <header className="bg-background/95 sticky top-0 z-20 px-4 pt-3 backdrop-blur-sm">
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
                                    aria-label={t('spending.event_title_aria')}
                                    className="font-heading text-primary border-border focus:border-accent min-w-0 flex-1 rounded-lg border bg-transparent px-1 text-2xl outline-none"
                                />
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setEditingTitle(true)}
                                    className="group flex min-w-0 items-center gap-2 text-left"
                                    aria-label={t('spending.rename_event')}
                                >
                                    <h1 className="font-heading text-primary truncate text-2xl">{title}</h1>
                                    <Pencil className="text-muted-foreground group-hover:text-primary size-4 shrink-0 transition-colors" />
                                </button>
                            )}
                            <div className="flex shrink-0 items-center gap-2">
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
                            {TABS.map((tabItem) => {
                                const active = tab === tabItem.key;
                                return (
                                    <button
                                        key={tabItem.key}
                                        type="button"
                                        onClick={() => setTab(tabItem.key)}
                                        className={`relative shrink-0 px-3 pt-1 pb-2.5 text-sm font-medium transition-colors ${
                                            active ? 'text-primary' : 'text-muted-foreground hover:text-primary'
                                        }`}
                                    >
                                        {t(tabItem.labelKey)}
                                        {tabItem.key === 'transfers' && transfers.length > 0 && (
                                            <span className="text-muted-foreground ml-1 tabular-nums">{transfers.length}</span>
                                        )}
                                        {active && <span className="bg-accent-strong absolute inset-x-3 -bottom-px h-0.5 rounded-full" />}
                                    </button>
                                );
                            })}
                        </nav>
                    </header>

                    <div className="flex flex-col gap-4 p-4">
                        {/* EXPENSES — total */}
                        {tab === 'expenses' && (
                            <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                                <div className="text-accent-light text-xs tracking-widest uppercase">{t('spending.total_spent')}</div>
                                <div className="font-heading mt-1 text-4xl">{fmt(event.total)}</div>
                                <div className="text-accent-light mt-1 text-sm">
                                    {event.people} {t(event.people === 1 ? 'spending.person_one' : 'spending.person_other')} · {visibleSpends.length}{' '}
                                    {t(visibleSpends.length === 1 ? 'spending.spend_one' : 'spending.spend_other')}
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
                            title={t('spending.new_payment_source')}
                            description={t('spending.new_source_desc')}
                        >
                            <div className="flex flex-col gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="source-name">{t('spending.name')}</Label>
                                    <Input
                                        id="source-name"
                                        autoFocus
                                        value={newSource.name}
                                        onChange={(e) => setNewSource((s) => ({ ...s, name: e.target.value }))}
                                        onKeyDown={(e) => e.key === 'Enter' && createSource()}
                                        placeholder={t('spending.source_name_placeholder')}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>{t('spending.type')}</Label>
                                    <div className="grid grid-cols-4 gap-2">
                                        {SOURCE_TYPE_META.map((opt) => {
                                            const active = newSource.type === opt.key;
                                            return (
                                                <button
                                                    key={opt.key}
                                                    type="button"
                                                    onClick={() => setNewSource((s) => ({ ...s, type: opt.key }))}
                                                    className={`flex flex-col items-center gap-1.5 rounded-xl border p-2.5 text-xs font-medium transition-colors ${
                                                        active
                                                            ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                                            : 'bg-surface-card text-text-secondary hover:border-accent'
                                                    }`}
                                                >
                                                    <opt.icon className="size-5" />
                                                    {t(opt.labelKey)}
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
                                    {savingSource ? t('spending.adding') : t('spending.add_source')}
                                </Button>
                            </div>
                        </ResponsiveModal>

                        {/* Record a payment (settle-up) — keyboard-safe drawer. From/to are members. */}
                        <ResponsiveModal
                            open={recording}
                            onOpenChange={(o) => !o && setRecording(false)}
                            title={t('spending.record_payment')}
                            description={t('spending.record_payment_desc')}
                            footer={
                                <Button
                                    type="button"
                                    onClick={recordTransfer}
                                    disabled={transferForm.processing || !transferValid}
                                    className="w-full"
                                >
                                    {t('common.save')}
                                </Button>
                            }
                        >
                            <div className="flex flex-col gap-4 pt-1">
                                <div className="grid gap-2">
                                    <Label>{t('spending.from')}</Label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {participants.map((p) => {
                                            const on = transferForm.data.from_key === p.key;
                                            return (
                                                <button
                                                    key={p.key}
                                                    type="button"
                                                    onClick={() => transferForm.setData('from_key', p.key)}
                                                    className={`flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                                                >
                                                    <PersonAvatar name={p.name} className="size-6 text-xs" />
                                                    <span className="text-primary min-w-0 flex-1 truncate">{p.name}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label>{t('spending.to')}</Label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {participants
                                            .filter((p) => p.key !== transferForm.data.from_key)
                                            .map((p) => {
                                                const on = transferForm.data.to_key === p.key;
                                                return (
                                                    <button
                                                        key={p.key}
                                                        type="button"
                                                        onClick={() => transferForm.setData('to_key', p.key)}
                                                        className={`flex items-center gap-2 rounded-lg border p-2 text-left text-sm transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                                                    >
                                                        <PersonAvatar name={p.name} className="size-6 text-xs" />
                                                        <span className="text-primary min-w-0 flex-1 truncate">{p.name}</span>
                                                    </button>
                                                );
                                            })}
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="transfer-amount">{t('spending.amount_currency', { currency: event.currency })}</Label>
                                    <Input
                                        id="transfer-amount"
                                        inputMode="decimal"
                                        onFocus={selectAllOnFocus}
                                        value={transferForm.data.amount}
                                        onChange={(e) => transferForm.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                                        placeholder="0.00"
                                    />
                                    <InputError message={transferForm.errors.amount} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="transfer-note">{t('spending.note_optional')}</Label>
                                    <Input
                                        id="transfer-note"
                                        value={transferForm.data.note}
                                        onChange={(e) => transferForm.setData('note', e.target.value)}
                                        placeholder={t('spending.transfer_note_placeholder')}
                                        maxLength={255}
                                    />
                                    <InputError message={transferForm.errors.note} />
                                </div>
                            </div>
                        </ResponsiveModal>

                        {/* TRANSFERS — recorded settle-up payments (+ record a new one) */}
                        {tab === 'transfers' && (
                            <section className="flex flex-col gap-2">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                        {t('spending.transfers_between_you')}
                                    </h2>
                                    {participants.length >= 2 && (
                                        <button
                                            type="button"
                                            onClick={() => setRecording(true)}
                                            className="text-primary flex items-center gap-1 text-sm font-medium"
                                        >
                                            <Plus className="size-4" /> {t('spending.record')}
                                        </button>
                                    )}
                                </div>
                                {transfers.length === 0 && (
                                    <div className="bg-surface-card text-muted-foreground rounded-xl border p-6 text-center text-sm">
                                        {t('spending.no_transfers_yet')}
                                        {participants.length >= 2 && (
                                            <button
                                                type="button"
                                                onClick={() => setRecording(true)}
                                                className="text-primary ml-1 font-medium underline"
                                            >
                                                {t('spending.record_payment')}
                                            </button>
                                        )}
                                    </div>
                                )}
                                {transfers.map((tr) => (
                                    <div key={tr.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                        <div className="flex shrink-0 items-center gap-1">
                                            <PersonAvatar name={tr.from} className="size-8 text-xs" />
                                            <ArrowRight className="text-muted-foreground size-4" />
                                            <PersonAvatar name={tr.to} className="size-8 text-xs" />
                                        </div>
                                        <div className="min-w-0 flex-1 text-sm">
                                            <div className="text-primary truncate font-medium">
                                                {tr.from} → {tr.to}
                                            </div>
                                            {tr.note && <div className="text-primary/80 truncate text-xs">{tr.note}</div>}
                                            <div className="text-muted-foreground text-xs">{dayLabel(t, tr.date)}</div>
                                        </div>
                                        <span className="text-primary font-mono text-sm font-semibold tabular-nums">{fmt(tr.amount)}</span>
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
                                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                            {t('spending.your_summary')}
                                        </h2>
                                        <div className="bg-surface-card flex flex-col rounded-2xl border p-4">
                                            {[
                                                { label: t('spending.total_spent'), value: event.total },
                                                { label: t('spending.you_paid'), value: mySummary.paid },
                                                { label: t('spending.your_share'), value: mySummary.share },
                                                ...(mySummary.sent > 0 ? [{ label: t('spending.transfers_sent'), value: mySummary.sent }] : []),
                                                ...(mySummary.received > 0
                                                    ? [{ label: t('spending.transfers_received'), value: mySummary.received }]
                                                    : []),
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
                                                    {t(
                                                        mySummary.net > 0
                                                            ? 'spending.you_get_back'
                                                            : mySummary.net < 0
                                                              ? 'spending.you_owe'
                                                              : 'spending.youre_settled_up',
                                                    )}
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
                                        {t(mySummary ? 'spending.everyone' : 'spending.balances')}
                                    </h2>
                                    {balances.map((b) => (
                                        <div key={b.id} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                                            <div className="flex items-center gap-3">
                                                <PersonAvatar name={b.name} className="size-8 text-sm" />
                                                <div>
                                                    <div className="text-primary text-sm font-medium">{b.name}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {t(b.net > 0 ? 'spending.gets_back' : b.net < 0 ? 'spending.owes' : 'spending.settled_up')}
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
                                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                            {t('spending.suggested_payments')}
                                        </h2>
                                        {suggestions.map((s, i) => (
                                            <div key={i} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                                                <div className="text-sm">
                                                    <span className="text-primary font-medium">{s.from.name}</span>
                                                    <span className="text-muted-foreground"> {t('spending.pays')} </span>
                                                    <span className="text-primary font-medium">{s.to.name}</span>
                                                    <span className="text-primary ml-2 font-mono font-semibold tabular-nums">{fmt(s.amount)}</span>
                                                </div>
                                                <Button size="sm" variant="outline" onClick={() => markPaid(s)}>
                                                    <Check className="size-4" /> {t('spending.mark_paid')}
                                                </Button>
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
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                        {t('spending.people_and_split')}
                                    </h2>
                                    <p className="text-muted-foreground -mt-0.5 text-xs">{t('spending.people_split_desc')}</p>
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
                                                            {t(p.kind === 'guest' ? 'spending.guest_word' : 'spending.member_word')}
                                                            {coverName && ` · ${t('spending.covered_by', { name: coverName })}`}
                                                        </div>
                                                    </div>
                                                    {canManageSettings ? (
                                                        <div className="flex items-center gap-1">
                                                            <button
                                                                type="button"
                                                                onClick={() => setWeight(p, p.weight - 1)}
                                                                disabled={p.weight <= 1}
                                                                aria-label={t('spending.fewer_people_for', { name: p.name })}
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
                                                                aria-label={t('spending.more_people_for', { name: p.name })}
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
                                                        <span className="text-muted-foreground text-xs">{t('spending.pays_label')}</span>
                                                        <select
                                                            value={p.coveredBy ?? ''}
                                                            disabled={coversOthers}
                                                            onChange={(e) => setCover(p, e.target.value || null)}
                                                            className="border-border bg-surface-card text-primary rounded-lg border py-1.5 pr-7 pl-3 text-base disabled:opacity-50 md:text-sm"
                                                            title={coversOthers ? t('spending.covers_someone_else_title') : undefined}
                                                        >
                                                            <option value="">{t('spending.their_own_share')}</option>
                                                            {participants
                                                                .filter((o) => o.key !== p.key && !o.coveredBy)
                                                                .map((o) => (
                                                                    <option key={o.key} value={o.key}>
                                                                        {t('spending.covered_by', { name: o.name })}
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
                                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                        {t('spending.event_word')}
                                    </h2>
                                    <button
                                        type="button"
                                        onClick={() => setSharing(true)}
                                        className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="text-primary flex items-center gap-2.5 font-medium">
                                            <UserPlus className="size-5" /> {t('spending.share_with_family')}
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setGuestSharing(true)}
                                        className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="text-primary flex items-center gap-2.5 font-medium">
                                            <Link2 className="size-5" /> {t('spending.guest_link')}
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
                                            <BarChart3 className="size-5" /> {t('spending.see_stats')}
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
                                                    <Loader2 className="size-4 animate-spin" /> {t('spending.reading_receipt')}
                                                </>
                                            ) : (
                                                <>
                                                    <Camera className="size-4" /> {t('spending.scan_receipt')}
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
                                    <Plus className="size-4" /> {t('spending.add_spend')}
                                </Button>
                            </div>
                        )}

                        {/* Add / edit a spend in a bottom drawer on mobile, a centered overlay on desktop.
                    Closing the drawer cancels. */}
                        <ResponsiveModal
                            open={adding}
                            onOpenChange={(o) => !o && closeForm()}
                            title={t(editing ? 'spending.edit_spend' : 'spending.new_spend')}
                            className="sm:max-w-lg"
                            autoFocusRef={amountRef}
                            footer={
                                <Button type="submit" form="spend-form" disabled={form.processing || !splitValid} className="w-full">
                                    {t('common.save')}
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
                                        <Label htmlFor="amount">{t('spending.amount')}</Label>
                                        {/* type=text (not number) so iOS honours inputMode=decimal and shows the real
                                    decimal pad. In comma-locales the separator key is a comma, so we normalise
                                    it to a dot (and strip stray chars) since the backend wants a plain number. */}
                                        <Input
                                            id="amount"
                                            form="spend-form"
                                            ref={amountRef}
                                            type="text"
                                            inputMode="decimal"
                                            onFocus={selectAllOnFocus}
                                            value={form.data.amount}
                                            onChange={(e) => form.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                                            placeholder="0.00"
                                        />
                                        <InputError message={form.errors.amount} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>{t('spending.currency')}</Label>
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
                                <div className="grid gap-3">
                                    <div className="grid gap-2">
                                        <Label>{t('spending.category')}</Label>
                                        <div className="flex flex-wrap gap-2">
                                            {event.categories.map((c) => {
                                                const Icon = categoryIcon(c);
                                                const active = form.data.category === c;
                                                return (
                                                    <button
                                                        key={c}
                                                        type="button"
                                                        onClick={() => form.setData('category', c)}
                                                        aria-pressed={active}
                                                        className={`flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium capitalize transition-colors ${
                                                            active
                                                                ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                                                : 'border-border text-primary hover:bg-surface'
                                                        }`}
                                                    >
                                                        <Icon className="size-4 shrink-0" />
                                                        {c}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>{t('spending.paid_by_field')}</Label>
                                        <Select value={form.data.payer_key} onValueChange={(v) => form.setData('payer_key', v)}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {/* Any participant can be the payer: family members and the guests we shared with. */}
                                                {participants.map((p) => (
                                                    <SelectItem key={p.key} value={p.key}>
                                                        {p.name}
                                                        {p.key === 'u' + authId ? t('spending.you_suffix') : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                {/* How this spend splits: everyone (default), a chosen subset, or exact amounts. */}
                                <div className="grid gap-2">
                                    <Label>{t('spending.split_label')}</Label>
                                    <div className="bg-surface grid grid-cols-3 gap-1 rounded-lg border p-1">
                                        {(
                                            [
                                                ['all', 'spending.split_everyone'],
                                                ['some', 'spending.split_only_some'],
                                                ['exact', 'spending.split_custom'],
                                            ] as const
                                        ).map(([k, labelKey]) => (
                                            <button
                                                key={k}
                                                type="button"
                                                onClick={() => {
                                                    form.setData('split', k);
                                                    if (k === 'exact' && Object.keys(form.data.share_amounts).length === 0) splitEqually();
                                                }}
                                                className={`rounded-md py-1.5 text-sm font-medium transition-colors ${
                                                    form.data.split === k ? 'bg-accent-strong text-accent-strong-foreground' : 'text-text-secondary'
                                                }`}
                                            >
                                                {t(labelKey)}
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
                                                <span className="text-muted-foreground text-xs">
                                                    {t('spending.amount_each_owes', { currency: form.data.currency })}
                                                </span>
                                                <button type="button" onClick={splitEqually} className="text-primary text-xs font-medium underline">
                                                    {t('spending.split_equally')}
                                                </button>
                                            </div>
                                            {participants.map((p) => (
                                                <div key={p.key} className="flex items-center gap-2">
                                                    <PersonAvatar name={p.name} className="size-6 text-xs" />
                                                    <span className="text-primary min-w-0 flex-1 truncate text-sm">{p.name}</span>
                                                    <Input
                                                        form="spend-form"
                                                        inputMode="decimal"
                                                        onFocus={selectAllOnFocus}
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
                                                    {money(exactSumCents, form.data.currency)} {t('spending.of')}{' '}
                                                    {money(amountCents, form.data.currency)}
                                                </span>
                                                <span>
                                                    {remainingCents === 0
                                                        ? t('spending.balanced')
                                                        : `${money(Math.abs(remainingCents), form.data.currency)} ${t(remainingCents > 0 ? 'spending.left' : 'spending.over')}`}
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="grid gap-2">
                                    <Label>{t('spending.paid_with')}</Label>
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
                                            <SelectItem value="none">{t('spending.not_specified')}</SelectItem>
                                            {sources.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name} <span className="text-muted-foreground">· {sourceTypeLabel(t, s.type)}</span>
                                                </SelectItem>
                                            ))}
                                            <SelectItem value="__new__" className="text-primary font-medium">
                                                <span className="flex items-center gap-1.5">
                                                    <Plus className="size-3.5" /> {t('spending.new_source')}
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="date">{t('spending.date')}</Label>
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
                                    <Label htmlFor="note">{t('spending.note_optional')}</Label>
                                    <Input
                                        id="note"
                                        form="spend-form"
                                        value={form.data.note}
                                        onChange={(e) => form.setData('note', e.target.value)}
                                        placeholder={t('spending.note_placeholder')}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="receipts">{t('spending.receipts_label')}</Label>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {/* Existing photos on the spend, each removable (queued for deletion on save). */}
                                        {existingReceipts.map((r) => (
                                            <div key={r.id} className="relative shrink-0">
                                                <img
                                                    src={r.url}
                                                    alt={t('spending.receipt_alt')}
                                                    className="border-border size-14 rounded-md border object-cover"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => form.setData('remove_receipt_ids', [...form.data.remove_receipt_ids, r.id])}
                                                    aria-label={t('spending.remove_photo')}
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
                                                    alt={t('spending.new_photo_alt')}
                                                    className="border-border size-14 rounded-md border object-cover"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData({
                                                            ...form.data,
                                                            receipts: form.data.receipts.filter((_, k) => k !== i),
                                                            receipts_thumb: form.data.receipts_thumb.filter((_, k) => k !== i),
                                                        })
                                                    }
                                                    aria-label={t('spending.remove_photo')}
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
                                                // A thumbnail per receipt (fall back to the full image if it can't be made),
                                                // kept index-aligned with `receipts`.
                                                const thumbs = await Promise.all(files.map((f) => makeThumbnail(f, 640)));
                                                form.setData({
                                                    ...form.data,
                                                    receipts: [...form.data.receipts, ...compressed],
                                                    receipts_thumb: [...form.data.receipts_thumb, ...thumbs.map((th, k) => th ?? compressed[k])],
                                                });
                                                e.target.value = ''; // let the same file be picked again after removal
                                            }}
                                        />
                                    </div>
                                    {form.progress && <progress value={form.progress.percentage} max="100" className="h-1 w-full" />}
                                    <InputError message={form.errors.receipts} />
                                </div>

                                {/* Advanced (edit only): move this expense to another spending list. */}
                                {editing && (
                                    <div className="border-border/70 mt-1 border-t pt-3">
                                        <button
                                            type="button"
                                            onClick={() => setAdvancedOpen((v) => !v)}
                                            className="text-primary flex w-full items-center justify-between text-sm font-medium"
                                        >
                                            {t('spending.advanced')}
                                            <ChevronDown
                                                className={`text-muted-foreground size-4 transition-transform ${advancedOpen ? 'rotate-180' : ''}`}
                                            />
                                        </button>
                                        {advancedOpen && (
                                            <div className="mt-3 grid gap-2">
                                                <Label>{t('spending.move_to_list')}</Label>
                                                {editingSpend?.payer.isGuest ? (
                                                    <p className="text-muted-foreground text-xs">{t('spending.move_guest_blocked')}</p>
                                                ) : moveTargets.length === 0 ? (
                                                    <p className="text-muted-foreground text-xs">{t('spending.move_no_targets')}</p>
                                                ) : (
                                                    <>
                                                        <Select value={moveTarget} onValueChange={setMoveTarget}>
                                                            <SelectTrigger>
                                                                <SelectValue placeholder={t('spending.move_pick_list')} />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {moveTargets.map((m) => (
                                                                    <SelectItem key={m.id} value={String(m.id)}>
                                                                        {m.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                        {moveTarget && (
                                                            <div className="bg-surface mt-1 grid gap-2 rounded-lg p-3">
                                                                {editingSpend && editingSpend.split !== 'all' && (
                                                                    <p className="text-xs" style={{ color: 'var(--warning)' }}>
                                                                        {t('spending.move_resets_split')}
                                                                    </p>
                                                                )}
                                                                {(() => {
                                                                    const tgt = moveTargets.find((m) => String(m.id) === moveTarget);
                                                                    return tgt && tgt.currency !== event.currency ? (
                                                                        <p className="text-muted-foreground text-xs">
                                                                            {t('spending.move_reconverts', { currency: tgt.currency })}
                                                                        </p>
                                                                    ) : null;
                                                                })()}
                                                                <div className="flex gap-2">
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        className="flex-1"
                                                                        onClick={() => setMoveTarget('')}
                                                                    >
                                                                        {t('common.cancel')}
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        className="flex-1"
                                                                        onClick={doMove}
                                                                        disabled={form.processing}
                                                                    >
                                                                        {t('spending.confirm_move')}
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </ResponsiveModal>

                        {/* EXPENSES — spend log, grouped by day, newest first, with a per-day subtotal */}
                        {tab === 'expenses' && (
                            <section className="flex flex-col gap-4">
                                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('spending.spend_log')}</h2>
                                {dayGroups.map((group) => (
                                    <div key={group.date} className="flex flex-col gap-2">
                                        <div className="flex items-center justify-between px-1">
                                            <span className="text-text-secondary text-sm font-medium">{dayLabel(t, group.date)}</span>
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
                                                            aria-label={t(
                                                                s.receipts.length > 1
                                                                    ? 'spending.view_receipt_photos'
                                                                    : 'spending.view_receipt_photo',
                                                                { count: s.receipts.length },
                                                            )}
                                                        >
                                                            <img
                                                                src={s.receipts[0].thumbUrl}
                                                                alt={t('spending.receipt_alt')}
                                                                className="border-border size-9 rounded-lg border object-cover"
                                                            />
                                                            {s.receipts.length > 1 && (
                                                                <span className="bg-accent-strong text-accent-strong-foreground absolute -top-1.5 -right-1.5 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold tabular-nums">
                                                                    {s.receipts.length}
                                                                </span>
                                                            )}
                                                        </button>
                                                    ) : (
                                                        <PersonAvatar name={s.payer.name ?? t('spending.guest_word')} />
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || s.category}</div>
                                                        <div className="text-muted-foreground truncate text-xs capitalize">
                                                            {(s.payer.name ?? t('spending.guest_word')).split(' ')[0]} · {s.category}
                                                            {s.split !== 'all' && (
                                                                <span className="normal-case">
                                                                    {' '}
                                                                    ·{' '}
                                                                    {s.split === 'exact'
                                                                        ? t('spending.custom_split')
                                                                        : t('spending.people_count', { count: s.shares.length })}
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
                                                                aria-label={t('spending.spend_actions_aria')}
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                <MoreVertical className="size-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onClick={() => startEdit(s)}>
                                                                <Pencil className="size-4" /> {t('common.edit')}
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem onClick={() => duplicate(s)}>
                                                                <Copy className="size-4" /> {t('spending.duplicate')}
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                className="text-destructive focus:text-destructive"
                                                                onClick={() => remove(s)}
                                                            >
                                                                <Trash2 className="size-4" /> {t('common.delete')}
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
                        <DialogTitle className="sr-only">{t('spending.receipt_photos_title')}</DialogTitle>
                        {viewing && (
                            <div className="relative">
                                <img
                                    src={viewing[viewIndex]}
                                    alt={t('spending.receipt_alt')}
                                    className="max-h-[85vh] w-full rounded-lg object-contain"
                                />
                                {viewing.length > 1 && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setViewIndex((i) => (i - 1 + viewing.length) % viewing.length)}
                                            aria-label={t('spending.previous_photo')}
                                            className="absolute top-1/2 left-2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur-sm"
                                        >
                                            <ChevronLeft className="size-6" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setViewIndex((i) => (i + 1) % viewing.length)}
                                            aria-label={t('spending.next_photo')}
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
