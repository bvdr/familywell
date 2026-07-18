import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Event = { id: number; name: string; currency: string; total: number; members: Member[] };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Spending', href: '/m/spending' }];

function Avatars({ members }: { members: Member[] }) {
    return (
        <div className="flex -space-x-2">
            {members.slice(0, 4).map((m) => (
                <span
                    key={m.id}
                    title={m.name}
                    className="bg-primary text-primary-foreground ring-background flex size-7 items-center justify-center rounded-full text-xs font-medium ring-2"
                >
                    {m.name.charAt(0).toUpperCase()}
                </span>
            ))}
            {members.length > 4 && <span className="text-muted-foreground ml-3 self-center text-xs">+{members.length - 4}</span>}
        </div>
    );
}

export default function Events({ events, currencies }: { events: Event[]; currencies: string[] }) {
    const [creating, setCreating] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', currency: currencies[0] });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('spending.events.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Spending" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">Spending</h1>
                        <p className="text-muted-foreground text-sm">Shared expense trackers</p>
                    </div>
                    <Button onClick={() => setCreating((v) => !v)}>
                        <Plus className="size-4" /> New event
                    </Button>
                </div>

                {creating && (
                    <form onSubmit={submit} className="bg-surface-card flex flex-col gap-3 rounded-xl border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Event name</Label>
                            <Input
                                id="name"
                                autoFocus
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Greece Vacation 2026"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Home currency</Label>
                            <Select value={data.currency} onValueChange={(v) => setData('currency', v)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={processing}>
                                Create event
                            </Button>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                {events.length === 0 && !creating && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <Wallet className="size-6" />
                        <p className="text-sm">No spend events yet. Create one to start tracking shared costs.</p>
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    {events.map((ev) => (
                        <Link
                            key={ev.id}
                            href={route('spending.show', ev.id)}
                            prefetch
                            className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-4 text-left transition-colors"
                        >
                            <div className="flex items-center gap-3">
                                <span className="bg-accent-subtle text-primary flex size-10 items-center justify-center rounded-lg">
                                    <Wallet className="size-5" />
                                </span>
                                <div>
                                    <div className="text-primary font-medium">{ev.name}</div>
                                    <div className="text-muted-foreground font-mono text-sm tabular-nums">{money(ev.total, ev.currency)}</div>
                                </div>
                            </div>
                            <Avatars members={ev.members} />
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
