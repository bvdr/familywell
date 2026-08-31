import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, ChartLine, Plus, RotateCcw, Settings, Wallet } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { useOptimistic } from '@/hooks/use-optimistic';

import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { LucideIcon } from '@/components/lucide-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Event = { id: number; name: string; currency: string; total: number; archived: boolean; icon: string | null; members: Member[] };

function Avatars({ members }: { members: Member[] }) {
    return (
        <div className="flex -space-x-2">
            {members.slice(0, 4).map((m) => (
                <MemberAvatar key={m.id} userId={m.id} name={m.name} ring className="size-7 text-xs" />
            ))}
            {members.length > 4 && <span className="text-muted-foreground ml-3 self-center text-xs">+{members.length - 4}</span>}
        </div>
    );
}

export default function Events({
    events,
    currencies,
    defaultCurrency = null,
}: {
    events: Event[];
    currencies: string[];
    defaultCurrency?: string | null;
}) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.spending'), href: '/m/spending' }];
    const [creating, setCreating] = useState(openNewOnArrival);
    useAddAction(() => setCreating(true));
    // Pre-select the family's default currency; fall back to the first supported one when unset.
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        currency: defaultCurrency ?? currencies[0],
        icon: null as string | null, // optional lucide key; null keeps the default Wallet icon
    });

    const archivedOpt = useOptimistic<boolean>();
    const isArchived = (ev: Event) => archivedOpt.get(ev.id, ev.archived);
    const active = events.filter((ev) => !isArchived(ev));
    const archived = events.filter((ev) => isArchived(ev));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('spending.events.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.spending')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">{t('nav.spending')}</h1>
                        <p className="text-muted-foreground text-sm">{t('dashboard.spending_sub')}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {/* Analytics: global totals/trends across all lists (top-right, where the request put it). */}
                        <Button asChild variant="outline" size="icon" title={t('spending.analytics')} aria-label={t('spending.analytics')}>
                            <Link href={route('spending.analytics')}>
                                <ChartLine className="size-4" />
                            </Link>
                        </Button>
                        {/* Module settings: default currency + expense categories. Directly right of Analytics. */}
                        <Button asChild variant="outline" size="icon" title={t('spending.settings')} aria-label={t('spending.settings')}>
                            <Link href={route('spending.settings')}>
                                <Settings className="size-4" />
                            </Link>
                        </Button>
                        {/* Hidden on mobile: the nav's context-aware "+" creates an event there. Desktop has no nav "+". */}
                        <Button className="hidden md:inline-flex" onClick={() => setCreating((v) => !v)}>
                            <Plus className="size-4" /> {t('create.spending')}
                        </Button>
                    </div>
                </div>

                {creating && (
                    <form onSubmit={submit} className="bg-surface-card flex flex-col gap-3 rounded-xl border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('spending.event_name')}</Label>
                            {/* Icon picker sits left of the name: pick a lucide icon for the list (defaults to Wallet). */}
                            <div className="flex items-start gap-2">
                                <IconPicker value={data.icon} onChange={(icon) => setData('icon', icon)} />
                                <div className="flex-1">
                                    <Input
                                        id="name"
                                        autoFocus
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder={t('spending.event_name_placeholder')}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('spending.home_currency')}</Label>
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
                                {t('spending.create_event')}
                            </Button>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                {t('common.cancel')}
                            </Button>
                        </div>
                    </form>
                )}

                {active.length === 0 && !creating && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <Wallet className="size-6" />
                        <p className="text-sm">{t('spending.no_events_empty')}</p>
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    {active.map((ev) => (
                        <Link
                            key={ev.id}
                            href={route('spending.show', ev.id)}
                            prefetch
                            className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-4 text-left transition-colors"
                        >
                            <div className="flex items-center gap-3">
                                <span className="bg-accent-subtle text-primary flex size-10 items-center justify-center rounded-lg">
                                    <LucideIcon name={ev.icon} className="size-5" />
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

                {archived.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                            <Archive className="size-3.5" /> {t('spending.archived')}
                        </h2>
                        {archived.map((ev) => (
                            <div key={ev.id} className="bg-surface flex items-center gap-3 rounded-xl border p-3 opacity-70">
                                <span className="text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg border">
                                    <LucideIcon name={ev.icon} className="size-4.5" />
                                </span>
                                <Link href={route('spending.show', ev.id)} className="min-w-0 flex-1">
                                    <div className="text-primary truncate text-sm font-medium">{ev.name}</div>
                                    <div className="text-muted-foreground font-mono text-xs tabular-nums">{money(ev.total, ev.currency)}</div>
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => {
                                        archivedOpt.set(ev.id, false);
                                        router.patch(
                                            route('spending.events.update', ev.id),
                                            { archived: false },
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                onSuccess: () => archivedOpt.clear(ev.id),
                                                onError: () => archivedOpt.clear(ev.id),
                                            },
                                        );
                                    }}
                                    aria-label={t('spending.restore_event')}
                                    className="text-muted-foreground hover:text-primary flex size-8 items-center justify-center rounded-lg transition-colors"
                                >
                                    <RotateCcw className="size-4" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
