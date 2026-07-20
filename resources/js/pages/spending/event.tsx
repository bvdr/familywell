import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Banknote,
    BarChart3,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    CreditCard,
    Landmark,
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
    UserPlus,
    Wallet,
    X,
} from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

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
    payer: { id: number; name: string };
    spent_at: string;
};
type Source = { id: number; name: string; type: string };
const SOURCE_TYPE_META = [
    { key: 'cash', label: 'Cash', icon: Banknote },
    { key: 'card', label: 'Card', icon: CreditCard },
    { key: 'bank', label: 'Bank', icon: Landmark },
    { key: 'other', label: 'Other', icon: Wallet },
] as const;
type Balance = { user_id: number; name: string; net: number };
type Suggestion = { from: { id: number; name: string }; to: { id: number; name: string }; amount: number };

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
    familyId,
    share,
}: {
    event: EventT;
    spends: Spend[];
    sources: Source[];
    balances: Balance[];
    suggestions: Suggestion[];
    familyId: number;
    share: ShareState;
}) {
    const authId = (usePage().props as { auth: { user: { id: number } } }).auth.user.id;
    const [adding, setAdding] = useState(false);
    const [sharing, setSharing] = useState(false);
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
    });

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
            form.transform((d) => ({ ...d, _method: 'patch' }));
            form.post(route('spending.spends.update', editing), { ...opts, forceFormData: true });
        } else {
            form.transform((d) => d); // reset any prior _method transform
            form.post(route('spending.spends.store', event.id), opts);
        }
    };

    const startEdit = (s: Spend) => {
        form.setData({
            amount: (s.amount / 100).toString(),
            currency: s.currency,
            category: s.category,
            payer_id: s.payer.id,
            source_id: s.source?.id ?? null,
            note: s.note ?? '',
            spent_at: s.spent_at,
            receipts: [],
            remove_receipt_ids: [],
        });
        setEditing(s.id);
        setAdding(true);
    };

    const startAdd = () => {
        form.reset();
        setEditing(null);
        setAdding(true);
    };

    const duplicate = (s: Spend) => router.post(route('spending.spends.duplicate', s.id), {}, { preserveScroll: true });
    const remove = (s: Spend) => router.delete(route('spending.spends.destroy', s.id), { preserveScroll: true });

    const markPaid = (s: Suggestion) => {
        router.post(route('spending.settle', event.id), { from_user_id: s.from.id, to_user_id: s.to.id, amount: s.amount }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={event.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                {/* Editable event title — tap it (or the pen) to rename. */}
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
                        className="font-heading text-primary border-border focus:border-accent w-full rounded-lg border bg-transparent px-1 text-2xl outline-none"
                    />
                ) : (
                    <button
                        type="button"
                        onClick={() => setEditingTitle(true)}
                        className="group -mb-1 flex items-center gap-2 self-start text-left"
                        aria-label="Rename event"
                    >
                        <h1 className="font-heading text-primary text-2xl">{title}</h1>
                        <Pencil className="text-muted-foreground group-hover:text-primary size-4 transition-colors" />
                    </button>
                )}

                {/* Total + display-currency selector */}
                <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                    <div className="flex items-start justify-between">
                        <div>
                            <div className="text-accent-light text-xs tracking-widest uppercase">Total spent</div>
                            <div className="font-heading mt-1 text-4xl">{fmt(event.total)}</div>
                            <div className="text-accent-light mt-1 text-sm">
                                {event.members.length} people · {spends.length} spends
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setSharing(true)}
                                aria-label="Share event"
                                className="border-text-inverse/25 text-text-inverse flex size-9 items-center justify-center rounded-lg border transition-colors hover:bg-white/10"
                            >
                                <UserPlus className="size-4.5" />
                            </button>
                            <Select value={display} onValueChange={setDisplay}>
                                <SelectTrigger className="border-text-inverse/25 text-text-inverse h-9 w-[88px] bg-transparent">
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
                </div>
                {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={event.name} />}

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
                        <Button type="button" onClick={createSource} disabled={!newSource.name.trim() || savingSource} className="mt-1 w-full">
                            {savingSource ? 'Adding…' : 'Add source'}
                        </Button>
                    </div>
                </ResponsiveModal>

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

                {/* Balances */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Balances</h2>
                    {balances.map((b) => (
                        <div key={b.user_id} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                            <div className="flex items-center gap-3">
                                <span className="bg-primary text-primary-foreground flex size-8 items-center justify-center rounded-full text-sm font-medium">
                                    {b.name.charAt(0).toUpperCase()}
                                </span>
                                <div>
                                    <div className="text-primary text-sm font-medium">{b.name}</div>
                                    <div className="text-muted-foreground text-xs">{b.net > 0 ? 'gets back' : b.net < 0 ? 'owes' : 'settled up'}</div>
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

                {/* Suggested settle-up */}
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
                                <Button size="sm" variant="outline" onClick={() => markPaid(s)}>
                                    <Check className="size-4" /> Mark paid
                                </Button>
                            </div>
                        ))}
                    </section>
                )}

                {/* Add / edit spend */}
                <Button onClick={startAdd} className="w-full">
                    <Plus className="size-4" /> Add spend
                </Button>

                {/* Add / edit a spend in a bottom drawer on mobile, a centered overlay on desktop.
                    Closing the drawer cancels. */}
                <ResponsiveModal
                    open={adding}
                    onOpenChange={(o) => !o && closeForm()}
                    title={editing ? 'Edit spend' : 'New spend'}
                    className="sm:max-w-lg"
                    autoFocusRef={amountRef}
                    footer={
                        <Button type="submit" form="spend-form" disabled={form.processing} className="w-full">
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

                {/* Spend log — grouped by day, newest first, with a per-day subtotal */}
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
                                            <span className="bg-accent-subtle flex size-9 items-center justify-center rounded-lg text-base">
                                                {CATEGORY[s.category]}
                                            </span>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || s.category}</div>
                                            <div className="text-muted-foreground truncate text-xs capitalize">
                                                {s.payer.name.split(' ')[0]} · {s.category}
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
                                                <div className="text-muted-foreground font-mono text-xs tabular-nums">{fmt(s.base_amount)}</div>
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
                                                <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => remove(s)}>
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
    );
}
