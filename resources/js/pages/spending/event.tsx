import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, MoreVertical, Pencil, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    receipt_url: string | null;
    payer: { id: number; name: string };
    spent_at: string;
};
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
    balances,
    suggestions,
    familyId,
}: {
    event: EventT;
    spends: Spend[];
    balances: Balance[];
    suggestions: Suggestion[];
    familyId: number;
}) {
    const authId = (usePage().props as { auth: { user: { id: number } } }).auth.user.id;
    const [adding, setAdding] = useState(false);
    // When set, the form is editing this spend id instead of creating a new one.
    const [editing, setEditing] = useState<number | null>(null);
    // Receipt being viewed in the lightbox overlay.
    const [viewing, setViewing] = useState<string | null>(null);
    // Display currency is cosmetic: it converts the base-currency figures for viewing. Default = base.
    const [display, setDisplay] = useState(event.currency);
    const fmt = (baseCents: number) => money(Math.round(baseCents * (event.rates[display] ?? 1)), display);

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
        note: string;
        spent_at: string;
        receipt: File | null;
        remove_receipt: boolean;
    }>({
        amount: '',
        currency: event.currency,
        category: 'food',
        payer_id: authId,
        note: '',
        spent_at: today(),
        receipt: null,
        remove_receipt: false,
    });

    // Receipt of the spend being edited (a new upload replaces it).
    const editingReceipt = editing ? (spends.find((s) => s.id === editing)?.receipt_url ?? null) : null;

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
            note: s.note ?? '',
            spent_at: s.spent_at,
            receipt: null,
            remove_receipt: false,
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
                {adding ? (
                    <form onSubmit={submitSpend} className="bg-surface-card flex flex-col gap-3 rounded-xl border p-4">
                        <div className="text-primary text-sm font-medium">{editing ? 'Edit spend' : 'New spend'}</div>
                        <div className="grid grid-cols-[1fr_auto] gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="amount">Amount</Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    autoFocus
                                    value={form.data.amount}
                                    onChange={(e) => form.setData('amount', e.target.value)}
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
                            <Label htmlFor="date">Date</Label>
                            <Input id="date" type="date" value={form.data.spent_at} onChange={(e) => form.setData('spent_at', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="note">Note (optional)</Label>
                            <Input
                                id="note"
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                placeholder="Dinner at the taverna"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="receipt">Receipt photo (optional)</Label>
                            <div className="flex items-center gap-3">
                                {editingReceipt && !form.data.receipt && !form.data.remove_receipt && (
                                    <div className="relative shrink-0">
                                        <img
                                            src={editingReceipt}
                                            alt="Current receipt"
                                            className="border-border size-12 rounded-md border object-cover"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => form.setData('remove_receipt', true)}
                                            aria-label="Remove receipt"
                                            className="bg-background text-muted-foreground hover:text-destructive border-border absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full border shadow-sm"
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </div>
                                )}
                                {editingReceipt && form.data.remove_receipt && (
                                    <span className="text-muted-foreground shrink-0 text-sm">
                                        Removed.{' '}
                                        <button
                                            type="button"
                                            onClick={() => form.setData('remove_receipt', false)}
                                            className="text-primary underline"
                                        >
                                            Undo
                                        </button>
                                    </span>
                                )}
                                <Input
                                    id="receipt"
                                    type="file"
                                    accept="image/*"
                                    className="file:text-primary text-muted-foreground cursor-pointer"
                                    onChange={async (e) => {
                                        const file = e.target.files?.[0];
                                        const receipt = file ? await compressImage(file) : null;
                                        form.setData('receipt', receipt);
                                        form.setData('remove_receipt', false); // a fresh pick supersedes a pending removal
                                    }}
                                />
                            </div>
                            {form.progress && <progress value={form.progress.percentage} max="100" className="h-1 w-full" />}
                            <InputError message={form.errors.receipt} />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save changes' : 'Add spend'}
                            </Button>
                            <Button type="button" variant="ghost" onClick={closeForm}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                ) : (
                    <Button onClick={startAdd} className="w-full">
                        <Plus className="size-4" /> Add spend
                    </Button>
                )}

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
                                    className="bg-surface-card hover:border-accent/60 flex cursor-pointer items-center justify-between rounded-xl border p-3 transition-colors"
                                >
                                    <div className="flex items-center gap-3">
                                        {s.receipt_url ? (
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setViewing(s.receipt_url);
                                                }}
                                                className="shrink-0"
                                                aria-label="View receipt"
                                            >
                                                <img
                                                    src={s.receipt_url}
                                                    alt="Receipt"
                                                    className="border-border size-9 rounded-lg border object-cover"
                                                />
                                            </button>
                                        ) : (
                                            <span className="bg-accent-subtle flex size-9 items-center justify-center rounded-lg text-base">
                                                {CATEGORY[s.category]}
                                            </span>
                                        )}
                                        <div>
                                            <div className="text-primary text-sm font-medium">{s.note || s.category}</div>
                                            <div className="text-muted-foreground text-xs capitalize">
                                                {s.payer.name} · {s.category}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1">
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

            {/* Receipt lightbox */}
            <Dialog open={!!viewing} onOpenChange={(open) => !open && setViewing(null)}>
                <DialogContent className="max-w-3xl border-none bg-transparent p-0 shadow-none">
                    <DialogTitle className="sr-only">Receipt</DialogTitle>
                    {viewing && <img src={viewing} alt="Receipt" className="max-h-[85vh] w-full rounded-lg object-contain" />}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
