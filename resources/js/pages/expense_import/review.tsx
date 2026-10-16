import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Tag, User, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { CategoryIcon } from '@/components/category-icon';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { catColor, catLabel, categoryMap, type CategoryDef } from '@/lib/categories';
import { batchTitle, postJson, shortDate, type ImportBatchCard, type ListOption, type MemberOption } from '@/lib/expense-import';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';

type Row = {
    id: number;
    date: string;
    merchant: string;
    amount: number;
    currency: string;
    kind: 'expense' | 'income' | 'refund' | 'transfer';
    flag: 'duplicate' | 'imported' | 'currency' | null;
    selected: boolean;
    category: string | null;
    imported: boolean;
};

type Props = {
    batch: ImportBatchCard & { eventId: number; payerKey: string };
    rows: Row[];
    lists: ListOption[];
    members: MemberOption[];
    categories: CategoryDef[];
    aiCategorize: boolean;
};

// The review step every import goes through: tick what becomes an expense, adjust categories, pick the list
// and payer, then import. After import the same page shows the result with Undo.
export default function ExpenseImportReview({ batch, rows, lists, members, categories, aiCategorize }: Props) {
    const t = useT();
    const catMap = useMemo(() => categoryMap(categories), [categories]);
    const reviewing = batch.status === 'review';
    const [eventId, setEventId] = useState(batch.eventId);
    const [payerKey, setPayerKey] = useState(batch.payerKey);
    const [picked, setPicked] = useState<Record<number, boolean>>(() => Object.fromEntries(rows.map((r) => [r.id, r.selected])));
    const [cats, setCats] = useState<Record<number, string | null>>(() => Object.fromEntries(rows.map((r) => [r.id, r.category])));
    const [saving, setSaving] = useState(false);
    const [suggesting, setSuggesting] = useState(false);

    // AI categories for shops the family's history doesn't know: a few merchants per call until none are left.
    // A category the person already picked is never overwritten.
    useEffect(() => {
        if (!reviewing || !aiCategorize || !rows.some((r) => r.kind === 'expense' && r.category === null)) return;
        let stop = false;
        (async () => {
            setSuggesting(true);
            try {
                for (let i = 0; i < 40 && !stop; i++) {
                    const res = await postJson<{ remaining: number; categories: Record<number, string | null> }>(
                        route('expense_import.suggest', batch.id, false),
                    );
                    setCats((prev) => {
                        const next = { ...prev };
                        for (const [id, key] of Object.entries(res.categories)) if (next[+id] == null && key) next[+id] = key;
                        return next;
                    });
                    if (res.remaining === 0) break;
                }
            } catch {
                // The AI is a nicety: on failure the rows simply stay in "Other".
            } finally {
                if (!stop) setSuggesting(false);
            }
        })();
        return () => {
            stop = true;
        };
    }, [batch.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const event = lists.find((l) => l.id === eventId);
    const chosen = rows.filter((r) => picked[r.id] && !r.imported);
    const totals = chosen.reduce<Record<string, number>>((acc, r) => ({ ...acc, [r.currency]: (acc[r.currency] ?? 0) + r.amount }), {});
    const expenses = rows.filter((r) => r.kind === 'expense' && !r.imported && r.flag !== 'imported');
    const allExpenses = expenses.length > 0 && expenses.every((r) => picked[r.id]);

    const submit = () => {
        setSaving(true);
        router.post(
            route('expense_import.import', batch.id),
            {
                event_id: eventId,
                payer_key: payerKey,
                rows: rows.map((r) => ({ id: r.id, selected: !!picked[r.id], category: cats[r.id] ?? null })),
            },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('expense_import.title'), href: route('expense_import.index') },
        { title: batchTitle(t, batch), href: route('expense_import.batch', batch.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('expense_import.review_title')} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4 pb-28">
                <div className="min-w-0">
                    <h1 className="font-heading text-primary text-2xl">
                        {reviewing ? t('expense_import.review_title') : t('expense_import.done_title')}
                    </h1>
                    <p className="text-muted-foreground truncate text-sm">
                        {batchTitle(t, batch)} · {shortDate(batch.createdAt)}
                    </p>
                </div>

                {reviewing ? (
                    <section className="bg-surface-card flex flex-col gap-4 rounded-xl border p-4 md:flex-row">
                        <div className="grid min-w-0 flex-1 gap-2">
                            <Label htmlFor="import-list">{t('expense_import.target_list')}</Label>
                            <Picker
                                id="import-list"
                                fallbackIcon={Wallet}
                                options={lists.map((l) => ({ value: String(l.id), label: `${l.name} · ${l.currency}` }))}
                                selected={[String(eventId)]}
                                onChange={([v]) => v && setEventId(Number(v))}
                            />
                        </div>
                        <div className="grid min-w-0 flex-1 gap-2">
                            <Label htmlFor="import-payer">{t('expense_import.payer')}</Label>
                            <Picker
                                id="import-payer"
                                fallbackIcon={User}
                                options={members.map((m) => ({ value: m.key, label: m.name }))}
                                selected={[payerKey]}
                                onChange={([v]) => v && setPayerKey(v)}
                            />
                        </div>
                    </section>
                ) : (
                    <section className="bg-surface-card flex flex-col items-start gap-3 rounded-xl border p-5">
                        <p className="text-primary text-sm">
                            {batch.status === 'undone' ? t('expense_import.done_undone') : t('expense_import.done_body', { count: batch.imported })}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {batch.status === 'imported' && (
                                <>
                                    <Button asChild>
                                        <Link href={route('spending.show', batch.eventId)}>{t('expense_import.open_list')}</Link>
                                    </Button>
                                    {batch.imported > 0 && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                window.confirm(t('expense_import.undo_confirm')) &&
                                                router.post(route('expense_import.undo', batch.id))
                                            }
                                        >
                                            {t('expense_import.undo')}
                                        </Button>
                                    )}
                                </>
                            )}
                            <Button variant="ghost" asChild>
                                <Link href={route('expense_import.index')}>{t('expense_import.back')}</Link>
                            </Button>
                        </div>
                    </section>
                )}

                {reviewing && <p className="text-muted-foreground px-1 text-sm">{t('expense_import.review_sub')}</p>}

                <div className="bg-surface-card overflow-hidden rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    {reviewing && (
                                        <Checkbox
                                            aria-label={t('expense_import.select_all')}
                                            checked={allExpenses}
                                            onCheckedChange={(v) =>
                                                setPicked((p) => ({ ...p, ...Object.fromEntries(expenses.map((r) => [r.id, v === true])) }))
                                            }
                                        />
                                    )}
                                </TableHead>
                                <TableHead>{t('expense_import.col_transaction')}</TableHead>
                                <TableHead className="text-right">{t('spending.amount')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((r) => (
                                <TableRow key={r.id} data-state={picked[r.id] ? 'selected' : undefined} className="align-top">
                                    <TableCell className="pt-3.5">
                                        <Checkbox
                                            aria-label={r.merchant}
                                            checked={r.imported || !!picked[r.id]}
                                            disabled={!reviewing || r.imported}
                                            onCheckedChange={(v) => setPicked((p) => ({ ...p, [r.id]: v === true }))}
                                        />
                                    </TableCell>
                                    {/* w-full max-w-0: the long bank text shrinks (truncates) instead of widening the table. */}
                                    <TableCell className="w-full max-w-0">
                                        <span className="text-primary block truncate text-[15px] font-medium">{r.merchant}</span>
                                        <span className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-xs">
                                            {shortDate(r.date)}
                                            <RowBadges row={r} t={t} />
                                        </span>
                                        {reviewing && picked[r.id] && !r.imported && (
                                            <div className="mt-2 max-w-xs">
                                                <Picker
                                                    ariaLabel={t('spending.category')}
                                                    fallbackIcon={Tag}
                                                    options={categories.map((c) => ({
                                                        value: c.key,
                                                        label: catLabel(t, catMap, c.key),
                                                        icon: ({ className }: { className?: string }) => (
                                                            <CategoryIcon
                                                                icon={c.icon}
                                                                className={className}
                                                                style={{ color: catColor(catMap, c.key) }}
                                                            />
                                                        ),
                                                    }))}
                                                    selected={[cats[r.id] ?? 'other']}
                                                    onChange={([k]) => k && setCats((c) => ({ ...c, [r.id]: k }))}
                                                />
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={`pt-3.5 text-right whitespace-nowrap tabular-nums ${r.kind === 'expense' ? 'text-primary' : 'text-muted-foreground'}`}
                                    >
                                        {r.kind === 'income' || r.kind === 'refund' ? '+' : ''}
                                        {money(r.amount, r.currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {reviewing && (
                // Rests on the phone's bottom nav: 4.75rem = the nav pill (70px) + a 6px gap, above the nav's own bottom
                // padding, which is 0.75rem + the home-indicator inset in a browser but max(0.5rem, inset/2) in the
                // installed PWA (mobile-tab-bar.tsx). Desktop: bottom-0. pb-28 on the page + the layout's own nav padding
                // keep the last row ~54px clear of the bar on every device (both include the inset).
                <div className="bg-background/95 fixed inset-x-0 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-40 border-t px-4 py-3 backdrop-blur md:bottom-0 md:left-(--sidebar-width) max-md:[@media(display-mode:standalone)]:bottom-[calc(4.75rem+max(0.5rem,env(safe-area-inset-bottom)/2))]">
                    <div className="mx-auto flex max-w-4xl items-center justify-between gap-3">
                        <span className="text-muted-foreground min-w-0 truncate text-sm">
                            {suggesting ? (
                                <span className="flex items-center gap-1.5">
                                    <Loader2 className="size-3.5 animate-spin" /> {t('expense_import.suggesting')}
                                </span>
                            ) : (
                                Object.entries(totals)
                                    .map(([c, v]) => money(v, c))
                                    .join(' + ') + (event ? ` → ${event.name}` : '')
                            )}
                        </span>
                        <Button onClick={submit} disabled={saving || chosen.length === 0} className="shrink-0">
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            {chosen.length ? t('expense_import.import_n', { count: chosen.length }) : t('expense_import.import_none')}
                        </Button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function RowBadges({ row, t }: { row: Row; t: TFn }) {
    const badges: [string, string][] = [];
    if (row.kind !== 'expense') badges.push([t(`expense_import.kind_${row.kind}`), 'bg-surface text-muted-foreground']);
    if (row.flag)
        badges.push([
            t(`expense_import.flag_${row.flag}`),
            row.flag === 'duplicate' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-surface text-muted-foreground',
        ]);
    return (
        <>
            {badges.map(([label, cls]) => (
                <span key={label} className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${cls}`}>
                    {label}
                </span>
            ))}
        </>
    );
}
