import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { money } from '@/lib/money';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2, UserRound } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Guest = { id: number; name: string; role: 'viewer' | 'editor' };
type Spend = {
    id: number;
    amount: number;
    currency: string;
    base_amount: number;
    category: string;
    note: string | null;
    payer: { name: string | null; guestId: number | null };
    spent_at: string;
};
type Balance = { id: string; name: string; net: number };
type Suggestion = { from: { id: string; name: string }; to: { id: string; name: string }; amount: number; canSettle: boolean };
type EventT = { name: string; currency: string; total: number; categories: string[]; currencies: string[] };

type Props = { token: string; event: EventT; guests: Guest[]; spends: Spend[]; balances: Balance[]; suggestions: Suggestion[] };

const CATEGORY: Record<string, string> = { food: '🍽️', transport: '🚗', lodging: '🏨', activities: '🎟️', other: '•' };
const today = () => new Date().toISOString().slice(0, 10);
const dayLabel = (date: string) => new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

// Public guest page reached via the link (after any password gate). No account: the visitor picks
// who they are from the guest list; that choice is remembered in localStorage per token. Editors get
// an add-expense form and can edit/delete the spends they added.
export default function GuestEvent({ token, event, guests, spends, balances, suggestions }: Props) {
    const storageKey = `familywell:guest:${token}`;
    const [meId, setMeId] = useState<number | null>(null);
    // Read the saved identity after mount (no SSR window), and drop it if that guest was removed.
    useEffect(() => {
        const saved = Number(localStorage.getItem(storageKey));
        setMeId(saved && guests.some((g) => g.id === saved) ? saved : null);
    }, [storageKey, guests]);

    const me = useMemo(() => guests.find((g) => g.id === meId) ?? null, [guests, meId]);
    const pick = (id: number) => {
        localStorage.setItem(storageKey, String(id));
        setMeId(id);
    };
    const forget = () => {
        localStorage.removeItem(storageKey);
        setMeId(null);
    };

    const [editing, setEditing] = useState<Spend | null>(null);
    const [adding, setAdding] = useState(false);
    const showForm = adding || editing !== null;

    // Identity gate: until the visitor says who they are, show only the picker.
    if (!me) {
        return (
            <Shell title={event.name}>
                <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                    <div className="text-accent-light text-xs tracking-widest uppercase">{event.name}</div>
                    <div className="font-heading mt-1 text-3xl">{money(event.total, event.currency)}</div>
                    <div className="text-accent-light mt-1 text-sm">shared with you</div>
                </div>
                <section className="mt-2 flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Who are you?</h2>
                    {guests.length === 0 && <p className="text-muted-foreground text-sm">No guests have been added yet.</p>}
                    {guests.map((g) => (
                        <button
                            key={g.id}
                            onClick={() => pick(g.id)}
                            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 text-left transition-colors"
                        >
                            <span className="bg-accent-subtle text-primary flex size-9 items-center justify-center rounded-full text-sm font-medium">
                                {g.name.charAt(0).toUpperCase()}
                            </span>
                            <span className="text-primary flex-1 font-medium">{g.name}</span>
                            <span className="text-muted-foreground text-xs">{g.role === 'editor' ? 'Can add' : 'View only'}</span>
                        </button>
                    ))}
                </section>
            </Shell>
        );
    }

    const myKey = `g${me.id}`;
    const myBalance = balances.find((b) => b.id === myKey);

    return (
        <Shell title={event.name}>
            {/* Identity bar */}
            <div className="flex items-center gap-2 text-sm">
                <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-full text-xs font-medium">
                    {me.name.charAt(0).toUpperCase()}
                </span>
                <span className="text-primary font-medium">You are {me.name}</span>
                <button onClick={forget} className="text-muted-foreground hover:text-primary ml-1 underline">
                    change
                </button>
            </div>

            {/* Total + my standing */}
            <div className="bg-surface-dark text-text-inverse rounded-2xl p-5">
                <div className="text-accent-light text-xs tracking-widest uppercase">Total spent</div>
                <div className="font-heading mt-1 text-4xl">{money(event.total, event.currency)}</div>
                <div className="text-accent-light mt-1 text-sm">
                    {event.name} · {guests.length} people · {spends.length} spends
                </div>
                {myBalance && myBalance.net !== 0 && (
                    <div className="border-text-inverse/15 mt-3 border-t pt-3 text-sm">
                        You {myBalance.net > 0 ? 'get back' : 'owe'}{' '}
                        <span className="font-mono font-semibold tabular-nums">{money(Math.abs(myBalance.net), event.currency)}</span>
                    </div>
                )}
            </div>

            {/* Balances */}
            <section className="flex flex-col gap-2">
                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Balances</h2>
                {balances.map((b) => (
                    <div
                        key={b.id}
                        className={`flex items-center justify-between rounded-xl border p-3 ${b.id === myKey ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                    >
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
                            {money(b.net, event.currency)}
                        </div>
                    </div>
                ))}
            </section>

            {/* Suggested settle-up (informational on the guest page) */}
            {suggestions.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Suggested payments</h2>
                    {suggestions.map((s, i) => (
                        <div key={i} className="bg-surface-card rounded-xl border p-3 text-sm">
                            <span className="text-primary font-medium">{s.from.name}</span>
                            <span className="text-muted-foreground"> pays </span>
                            <span className="text-primary font-medium">{s.to.name}</span>
                            <span className="text-primary ml-2 font-mono font-semibold tabular-nums">{money(s.amount, event.currency)}</span>
                        </div>
                    ))}
                </section>
            )}

            {/* Spends */}
            <section className="flex flex-col gap-2 pb-24">
                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Spends</h2>
                {spends.map((s) => {
                    const mine = s.payer.guestId === me.id;
                    return (
                        <div key={s.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                            <span className="bg-accent-subtle flex size-9 shrink-0 items-center justify-center rounded-lg text-base">
                                {CATEGORY[s.category] ?? '•'}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-primary line-clamp-2 text-sm font-medium">{s.note || s.category}</div>
                                <div className="text-muted-foreground truncate text-xs capitalize">
                                    {(s.payer.name ?? 'Guest').split(' ')[0]} · {dayLabel(s.spent_at)}
                                </div>
                            </div>
                            <div className="shrink-0 text-right">
                                <div className="text-primary font-mono text-sm font-semibold tabular-nums">{money(s.amount, s.currency)}</div>
                                {s.currency !== event.currency && (
                                    <div className="text-muted-foreground font-mono text-xs tabular-nums">{money(s.base_amount, event.currency)}</div>
                                )}
                            </div>
                            {/* Editors manage only their own entries. */}
                            {me.role === 'editor' && mine && (
                                <div className="flex flex-col gap-1">
                                    <button
                                        onClick={() => setEditing(s)}
                                        aria-label="Edit"
                                        className="text-muted-foreground hover:text-primary flex size-7 items-center justify-center"
                                    >
                                        <Pencil className="size-4" />
                                    </button>
                                    <button
                                        onClick={() =>
                                            router.delete(`/m/spending/guest/${token}/spends/${s.id}`, {
                                                data: { guest_id: me.id },
                                                preserveScroll: true,
                                            })
                                        }
                                        aria-label="Delete"
                                        className="text-muted-foreground flex size-7 items-center justify-center hover:text-[var(--warning)]"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            )}
                        </div>
                    );
                })}
                {spends.length === 0 && <p className="text-muted-foreground text-sm">No spends yet.</p>}
            </section>

            {/* Editor add button */}
            {me.role === 'editor' && (
                <button
                    onClick={() => {
                        setEditing(null);
                        setAdding(true);
                    }}
                    className="bg-primary text-primary-foreground fixed right-4 bottom-6 z-10 flex items-center gap-2 rounded-full px-5 py-3 text-sm font-semibold shadow-lg sm:right-[calc(50%-20rem)]"
                >
                    <Plus className="size-5" /> Add expense
                </button>
            )}

            {showForm && (
                <SpendForm
                    token={token}
                    me={me}
                    event={event}
                    editing={editing}
                    onClose={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}
        </Shell>
    );
}

// Shared page chrome: no app shell, no auth. Same visual language as the read-only public view.
function Shell({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="bg-background min-h-screen">
            <Head title={`${title} · FamilyWell`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2 pt-2">
                    <span className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-lg text-xs font-bold">
                        FW
                    </span>
                    <span className="text-muted-foreground text-sm">FamilyWell · shared with you</span>
                </div>
                {children}
            </div>
        </div>
    );
}

function SpendForm({ token, me, event, editing, onClose }: { token: string; me: Guest; event: EventT; editing: Spend | null; onClose: () => void }) {
    const form = useForm({
        guest_id: me.id,
        amount: editing ? (editing.amount / 100).toString() : '',
        currency: editing?.currency ?? event.currency,
        category: editing?.category ?? 'food',
        note: editing?.note ?? '',
        spent_at: editing?.spent_at ?? today(),
    });

    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (editing) {
            form.patch(`/m/spending/guest/${token}/spends/${editing.id}`, opts);
        } else {
            form.post(`/m/spending/guest/${token}/spends`, opts);
        }
    };

    return (
        <Sheet open onOpenChange={(v) => !v && onClose()}>
            <SheetContent side="bottom" className="max-h-[92vh] overflow-y-auto rounded-t-2xl px-4 pb-8 sm:mx-auto sm:max-w-md">
                <SheetHeader className="px-0 text-left">
                    <SheetTitle className="font-heading text-primary text-2xl">{editing ? 'Edit expense' : 'Add expense'}</SheetTitle>
                    <p className="text-muted-foreground -mt-1 flex items-center gap-1.5 text-sm">
                        <UserRound className="size-3.5" /> paid by {me.name}
                    </p>
                </SheetHeader>

                <div className="mt-2 flex flex-col gap-4">
                    <div className="flex gap-2">
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="amount">Amount</Label>
                            <Input
                                id="amount"
                                autoFocus
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                placeholder="0.00"
                            />
                        </div>
                        <div className="grid w-28 gap-1.5">
                            <Label>Currency</Label>
                            <Select value={form.data.currency} onValueChange={(v) => form.setData('currency', v)}>
                                <SelectTrigger>
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

                    <div className="grid gap-1.5">
                        <Label>Category</Label>
                        <div className="grid grid-cols-5 gap-2">
                            {event.categories.map((c) => {
                                const active = form.data.category === c;
                                return (
                                    <button
                                        key={c}
                                        type="button"
                                        onClick={() => form.setData('category', c)}
                                        className={`flex flex-col items-center gap-1 rounded-xl border p-2 text-xs font-medium capitalize transition-colors ${
                                            active
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'bg-surface-card text-text-secondary hover:border-accent'
                                        }`}
                                    >
                                        <span className="text-base">{CATEGORY[c] ?? '•'}</span>
                                        {c}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Optional" />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="spent_at">Date</Label>
                        <Input id="spent_at" type="date" value={form.data.spent_at} onChange={(e) => form.setData('spent_at', e.target.value)} />
                    </div>

                    <Button type="button" onClick={submit} disabled={!form.data.amount.trim() || form.processing} className="mt-1 w-full">
                        {form.processing ? (
                            'Saving…'
                        ) : editing ? (
                            'Save changes'
                        ) : (
                            <>
                                <Check className="size-4" /> Add expense
                            </>
                        )}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
