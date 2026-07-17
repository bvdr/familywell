import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
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
    // Display currency is cosmetic: it converts the base-currency figures for viewing. Default = base.
    const [display, setDisplay] = useState(event.currency);
    const fmt = (baseCents: number) => money(Math.round(baseCents * (event.rates[display] ?? 1)), display);

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

    const form = useForm({
        amount: '',
        currency: event.currency,
        category: 'food',
        payer_id: authId,
        note: '',
        spent_at: today(),
    });

    const addSpend: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('spending.spends.store', event.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('amount', 'note');
                setAdding(false);
            },
        });
    };

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
                                <span className="bg-accent text-text-inverse flex size-8 items-center justify-center rounded-full text-sm font-medium">
                                    {b.name.charAt(0).toUpperCase()}
                                </span>
                                <div>
                                    <div className="text-primary text-sm font-medium">{b.name}</div>
                                    <div className="text-muted-foreground text-xs">{b.net > 0 ? 'gets back' : b.net < 0 ? 'owes' : 'settled up'}</div>
                                </div>
                            </div>
                            <div
                                className={`text-sm font-semibold ${b.net > 0 ? 'text-accent' : b.net < 0 ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}
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
                                    <span className="text-primary ml-2 font-semibold">{fmt(s.amount)}</span>
                                </div>
                                <Button size="sm" variant="outline" onClick={() => markPaid(s)}>
                                    <Check className="size-4" /> Mark paid
                                </Button>
                            </div>
                        ))}
                    </section>
                )}

                {/* Add spend */}
                {adding ? (
                    <form onSubmit={addSpend} className="bg-surface-card flex flex-col gap-3 rounded-xl border p-4">
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
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Add spend
                            </Button>
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                ) : (
                    <Button onClick={() => setAdding(true)} className="w-full">
                        <Plus className="size-4" /> Add spend
                    </Button>
                )}

                {/* Spend log — original currency, with the converted display amount when they differ */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Spend log</h2>
                    {spends.map((s) => (
                        <div key={s.id} className="bg-surface-card flex items-center justify-between rounded-xl border p-3">
                            <div className="flex items-center gap-3">
                                <span className="bg-accent-subtle flex size-9 items-center justify-center rounded-lg text-base">
                                    {CATEGORY[s.category]}
                                </span>
                                <div>
                                    <div className="text-primary text-sm font-medium">{s.note || s.category}</div>
                                    <div className="text-muted-foreground text-xs capitalize">
                                        {s.payer.name} · {s.category}
                                    </div>
                                </div>
                            </div>
                            <div className="text-right">
                                <div className="text-primary text-sm font-semibold">{money(s.amount, s.currency)}</div>
                                {s.currency !== display && <div className="text-muted-foreground text-xs">{fmt(s.base_amount)}</div>}
                            </div>
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
