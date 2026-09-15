import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Archive, ChartLine, ChevronRight, Plus, RotateCcw, Settings, Tag, Wallet } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

import { useOptimistic } from '@/hooks/use-optimistic';

import { CategoryIcon } from '@/components/category-icon';
import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { LucideIcon } from '@/components/lucide-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { catColor, catIconName, catLabel, categoryMap, defaultCategoryKey, type CategoryDef } from '@/lib/categories';
import { groupByDateBucket, localToday } from '@/lib/date-groups';
import { useLocale, useT } from '@/lib/i18n';
import { money } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Event = {
    id: number;
    name: string;
    currency: string; // display currency of the card total
    baseCurrency: string; // what a new spend is frozen into; pre-selected in the quick-add form
    total: number;
    archived: boolean;
    icon: string | null;
    canEdit: boolean;
    members: Member[];
};
// One row of the Expenses feed. `amount` is in the spend's own currency; `converted` in the feed currency.
type Expense = {
    id: number;
    amount: number;
    currency: string;
    converted: number;
    category: string;
    note: string | null;
    payer: string | null;
    date: string;
    list: string;
    list_id: number;
};

const today = () => new Date().toISOString().slice(0, 10);
// Select the whole value when the amount input gains focus, so a tap-and-type overwrites it.
const selectAllOnFocus = (e: React.FocusEvent<HTMLInputElement>) => e.target.select();

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
    thisMonth,
    expenses,
    feedCap,
    categories,
    members,
}: {
    events: Event[];
    currencies: string[];
    defaultCurrency?: string | null;
    // Month-to-date total across every visible list, already rolled up server-side into one currency.
    thisMonth: { total: number; currency: string };
    expenses: Expense[];
    feedCap: number;
    categories: CategoryDef[];
    members: Member[]; // whole family: payer options when no list is picked (the "Others" list takes everyone)
}) {
    const t = useT();
    const locale = useLocale();
    const authId = usePage<{ auth: { user: { id: number } } }>().props.auth.user.id;
    // Month name in the viewer's locale ("September" / "Septembrie"); the server only sends the total.
    const monthName = new Date().toLocaleDateString(locale, { month: 'long' });
    const shortDate = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString(locale, { month: 'short', day: 'numeric' });
    // Expenses feed section headers: relative buckets (Today … Earlier this month) then one per month.
    // The feed arrives newest-first, so the groups come out already in display order.
    const viewerToday = localToday();
    const expenseGroups = groupByDateBucket(expenses, (e) => e.date, viewerToday);
    const bucketLabel = (key: string) => {
        if (key === 'today') return t('dashboard.today');
        if (key === 'yesterday') return t('spending.yesterday');
        if (key === 'this_week') return t('spending.earlier_this_week');
        if (key === 'last_week') return t('spending.last_week');
        if (key === 'this_month') return t('spending.earlier_this_month');
        // 'YYYY-MM': month name, with the year only when it is not the current one. Capitalised by hand
        // (ro month names are lowercase) rather than CSS capitalize, which would also title-case the
        // multi-word labels above.
        const label = new Date(key + '-01T00:00:00').toLocaleDateString(locale, {
            month: 'long',
            year: key.slice(0, 4) === viewerToday.slice(0, 4) ? undefined : 'numeric',
        });
        return label.charAt(0).toUpperCase() + label.slice(1);
    };
    const catMap = categoryMap(categories);
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.spending'), href: '/m/spending' }];

    // Expenses / Lists is a client-side tab (same pattern as Shopping's Lists / Shops): no navigation,
    // identical header + widget on both, instant switch. Expenses is the default — Spending is
    // expense-first; lists are the secondary way in.
    const [view, setView] = useState<'expenses' | 'lists'>('expenses');

    // The "+" (mobile nav + desktop header, and ?new=1 from the global quick-add) ADDS AN EXPENSE. Creating
    // a list is reachable from the Lists tab only.
    const [adding, setAdding] = useState(openNewOnArrival);
    const [creating, setCreating] = useState(false);
    useAddAction(() => setAdding(true));
    const amountRef = useRef<HTMLInputElement>(null);

    // Lists the viewer may add spends to; the picker offers these plus the "Others" default (value '').
    const editable = events.filter((ev) => ev.canEdit && !ev.archived);
    // Catch-all defaults on both axes ("Other" category + "Others" list) so an expense can be added fast
    // without picking anything.
    const defaultCategory = defaultCategoryKey(categories);
    // Category picker rows: the coloured category icon wrapped as a Picker-compatible component.
    // ponytail: rebuilt per render (useT() returns a fresh closure, so memoising is moot) — the wrappers
    // change identity and React remounts the ~8 tiny SVGs while the drawer is open; not measurable.
    const categoryOptions = categories.map((c) => ({
        value: c.key,
        label: catLabel(t, catMap, c.key),
        icon: ({ className }: { className?: string }) => <CategoryIcon icon={c.icon} className={className} style={{ color: c.color }} />,
    }));
    // ponytail: quick-add covers amount/currency/category/list/payer/date/note. Split, receipts, payment
    // source and guests stay on the list page (tap the row to edit) — add here only if people ask.
    const spend = useForm({
        event_id: '' as string, // '' => the family's "Others" list (resolved server-side)
        amount: '',
        currency: defaultCurrency ?? currencies[0],
        category: defaultCategory,
        payer_key: 'u' + authId,
        spent_at: today(),
        note: '',
    });
    const chosen = editable.find((ev) => String(ev.id) === spend.data.event_id);
    // Payer options follow the chosen list's roster; with no list, the "Others" list has everyone.
    const payers = chosen ? chosen.members : members;
    const pickList = (id: string) => {
        const ev = editable.find((e) => String(e.id) === id);
        spend.setData((d) => ({
            ...d,
            event_id: id,
            // A list's spends are frozen into its base currency, so pre-select that; back to the family default otherwise.
            currency: ev ? ev.baseCurrency : (defaultCurrency ?? currencies[0]),
            // Keep the payer if still on the roster, else fall back to the viewer.
            payer_key: (ev ? ev.members : members).some((m) => 'u' + m.id === d.payer_key) ? d.payer_key : 'u' + authId,
        }));
    };
    const closeAdd = () => {
        spend.reset();
        setAdding(false);
    };
    const submitSpend: FormEventHandler = (e) => {
        e.preventDefault();
        spend.transform((d) => ({ ...d, event_id: d.event_id || null }));
        spend.post(route('spending.spends.quick'), { preserveScroll: true, onSuccess: closeAdd });
    };

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
                        {/* Hidden on mobile: the nav's context-aware "+" adds an expense there. Desktop has no nav "+". */}
                        <Button className="hidden md:inline-flex" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('spending.add_expense')}
                        </Button>
                    </div>
                </div>

                {/* This-month widget: total spent month-to-date across all visible lists. Tapping opens
                    Analytics pre-filtered to the same period (period=mtd), where the same number is broken
                    down by category/day. Mirrors the analytics "Total spent" card so the two read alike. */}
                <Link
                    href={route('spending.analytics', { period: 'mtd' })}
                    prefetch
                    className="bg-surface-card hover:border-accent flex items-center justify-between rounded-xl border p-4 transition-colors"
                >
                    <div className="flex flex-col gap-0.5">
                        <span className="font-heading text-primary text-lg capitalize">{monthName}</span>
                        <span className="text-primary font-mono text-2xl font-semibold tabular-nums">
                            {money(thisMonth.total, thisMonth.currency)}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {t('spending.total_spent')} · {t('spending.analytics')}
                        </span>
                    </div>
                    <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                </Link>

                {/* Client-side Expenses / Lists tab — no page load, same bar in both. */}
                <div className="border-border bg-surface flex rounded-full border p-1">
                    {(['expenses', 'lists'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => setView(v)}
                            className={`flex-1 rounded-full px-4 py-1.5 text-sm font-medium capitalize transition-colors ${
                                view === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'
                            }`}
                        >
                            {t(v === 'expenses' ? 'spending.tab_expenses' : 'spending.tab_lists')}
                        </button>
                    ))}
                </div>

                {/* Quick-add expense: bottom drawer on mobile, centered overlay on desktop. Closing cancels. */}
                <ResponsiveModal
                    open={adding}
                    onOpenChange={(o) => !o && closeAdd()}
                    title={t('spending.new_spend')}
                    autoFocusRef={amountRef}
                    footer={
                        <Button type="submit" form="quick-spend" disabled={spend.processing || !spend.data.amount} className="w-full">
                            {t('common.save')}
                        </Button>
                    }
                >
                    {/* The <form> holds no fields directly (inputs join via form="quick-spend"; the dropdowns
                        stay outside) so Radix's hidden native <select>s don't break iOS's prev/next keys. */}
                    <form id="quick-spend" onSubmit={submitSpend} className="hidden" />
                    <div className="flex flex-col gap-3">
                        <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="quick-amount">{t('spending.amount')}</Label>
                                {/* type=text + inputMode=decimal for the real iOS decimal pad; commas normalised to dots. */}
                                <Input
                                    id="quick-amount"
                                    form="quick-spend"
                                    ref={amountRef}
                                    type="text"
                                    inputMode="decimal"
                                    onFocus={selectAllOnFocus}
                                    value={spend.data.amount}
                                    onChange={(e) => spend.setData('amount', e.target.value.replace(/,/g, '.').replace(/[^\d.]/g, ''))}
                                    placeholder="0.00"
                                />
                                <InputError message={spend.errors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label>{t('spending.currency')}</Label>
                                <Select value={spend.data.currency} onValueChange={(v) => spend.setData('currency', v)}>
                                    <SelectTrigger className="w-[92px]">
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
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('spending.category')}</Label>
                            {/* Searchable like the list picker below: type to filter the (possibly long) category list. */}
                            <Picker
                                searchable
                                fallbackIcon={Tag}
                                options={categoryOptions}
                                selected={[spend.data.category]}
                                onChange={(next) => spend.setData('category', next[0] ?? defaultCategory)}
                            />
                            <InputError message={spend.errors.category} />
                        </div>
                        {/* Which list the expense goes to. Optional: no pick => the family's "Others" list. */}
                        <div className="grid gap-2">
                            <Label>{t('spending.list')}</Label>
                            {/* Searchable (families end up with dozens of lists): type to filter. '__others' stands in
                                for the '' default because the Picker treats an empty selection as "nothing picked".
                                ponytail: no create-on-the-fly — events.store redirects to the new list's page, which
                                would drop the half-filled drawer; add when a JSON create endpoint exists. */}
                            <Picker
                                searchable
                                fallbackIcon={Wallet}
                                options={[
                                    { value: '__others', label: t('spending.others_list_hint') },
                                    ...editable.map((ev) => ({ value: String(ev.id), label: ev.name })),
                                ]}
                                selected={[spend.data.event_id || '__others']}
                                onChange={(next) => pickList(next[0] === '__others' ? '' : (next[0] ?? ''))}
                            />
                            <InputError message={spend.errors.event_id} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label>{t('spending.paid_by_label')}</Label>
                                <Select value={spend.data.payer_key} onValueChange={(v) => spend.setData('payer_key', v)}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {payers.map((m) => (
                                            <SelectItem key={m.id} value={'u' + m.id}>
                                                {m.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={spend.errors.payer_key} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="quick-date">{t('spending.date')}</Label>
                                <Input
                                    id="quick-date"
                                    form="quick-spend"
                                    type="date"
                                    value={spend.data.spent_at}
                                    onChange={(e) => spend.setData('spent_at', e.target.value)}
                                />
                                <InputError message={spend.errors.spent_at} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="quick-note">{t('spending.note')}</Label>
                            <Input
                                id="quick-note"
                                form="quick-spend"
                                value={spend.data.note}
                                onChange={(e) => spend.setData('note', e.target.value)}
                                placeholder={t('spending.note_placeholder')}
                            />
                            <InputError message={spend.errors.note} />
                        </div>
                    </div>
                </ResponsiveModal>

                {/* EXPENSES — flat feed of every spend across the visible lists, newest first. Rows follow the
                    analytics drill-down style; tapping one opens its list (where it can be edited). */}
                {view === 'expenses' &&
                    (expenses.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                            <Wallet className="size-6" />
                            <p className="text-sm">{t('spending.no_expenses_empty')}</p>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4">
                            {expenseGroups.map((group) => (
                                <section key={group.key} className="flex flex-col gap-2">
                                    <h3 className="text-text-secondary px-1 text-sm font-medium">{bucketLabel(group.key)}</h3>
                                    <div className="bg-surface-card flex flex-col rounded-xl border px-4 py-1">
                                        <ul className="flex flex-col divide-y">
                                            {group.items.map((e) => (
                                                <li key={e.id}>
                                                    <Link
                                                        href={route('spending.show', e.list_id)}
                                                        className="hover:bg-surface active:bg-surface -mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors"
                                                    >
                                                        <div className="flex min-w-0 items-center gap-2.5">
                                                            <CategoryIcon
                                                                icon={catIconName(catMap, e.category)}
                                                                className="size-4 shrink-0"
                                                                style={{ color: catColor(catMap, e.category) }}
                                                            />
                                                            <div className="min-w-0">
                                                                <div className="text-primary truncate text-sm">
                                                                    {e.note || catLabel(t, catMap, e.category)}
                                                                </div>
                                                                <div className="text-muted-foreground truncate text-xs">
                                                                    {catLabel(t, catMap, e.category)} · {e.list} · {shortDate(e.date)}
                                                                    {e.payer && <> · {e.payer.split(' ')[0]}</>}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="shrink-0 text-right">
                                                            <div className="text-primary font-mono text-sm font-semibold tabular-nums">
                                                                {money(e.amount, e.currency)}
                                                            </div>
                                                            {e.currency !== thisMonth.currency && (
                                                                <div className="text-muted-foreground font-mono text-xs tabular-nums">
                                                                    {money(e.converted, thisMonth.currency)}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </section>
                            ))}
                            {expenses.length >= feedCap && (
                                <p className="text-muted-foreground py-1 text-center text-xs">{t('spending.feed_capped', { count: feedCap })}</p>
                            )}
                        </div>
                    ))}

                {/* LISTS — the list cards (the former overview), with list creation living here now. */}
                {view === 'lists' && (
                    <>
                        {!creating && (
                            <Button variant="outline" className="self-start" onClick={() => setCreating(true)}>
                                <Plus className="size-4" /> {t('spending.new_list')}
                            </Button>
                        )}

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
                    </>
                )}
            </div>
        </AppLayout>
    );
}
