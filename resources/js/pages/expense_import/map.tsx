import { currencyOptions } from '@/lib/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Picker } from '@/components/picker';
import { Segmented } from '@/components/segmented';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

type Props = {
    batch: { id: number; filename: string | null };
    headers: string[];
    samples: string[][];
    currencies: string[];
    defaultCurrency: string;
};

type Field = 'date' | 'merchant' | 'amount' | 'debit' | 'credit' | 'currency' | 'reference';

// A bank we don't recognise: match its columns once (saved for this family + this header row), then review.
export default function ExpenseImportMap({ batch, headers, samples, currencies, defaultCurrency }: Props) {
    const t = useT();
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    // Start from a guess on the header names, so a typical file needs one or two taps.
    const guess = (re: RegExp) => {
        const i = headers.findIndex((h) => re.test(h.toLowerCase()));
        return i >= 0 ? String(i) : '';
    };
    const [label, setLabel] = useState('');
    const [mode, setMode] = useState<'single' | 'split'>(guess(/debit/) && guess(/credit/) ? 'split' : 'single');
    const [cols, setCols] = useState<Record<Field, string>>({
        date: guess(/dat/),
        merchant: guess(/descr|detal|merchant|shop|name|benef|text|desc/),
        amount: guess(/amount|sum|suma|valoare|value/),
        debit: guess(/debit/),
        credit: guess(/credit/),
        currency: guess(/curr|valut|moneda/),
        reference: guess(/ref|id/),
    });
    const [fixedCurrency, setFixedCurrency] = useState(defaultCurrency);
    const [expensesPositive, setExpensesPositive] = useState(false);
    const [saving, setSaving] = useState(false);

    const options = (optional: boolean) => [
        ...(optional ? [{ value: '', label: t('expense_import.map_none') }] : []),
        ...headers.map((h, i) => ({ value: String(i), label: h || `#${i + 1}` })),
    ];
    const set = (f: Field) => (v: string[]) => setCols((c) => ({ ...c, [f]: v[0] ?? '' }));
    const num = (v: string) => (v === '' ? null : Number(v));

    const save = () => {
        setSaving(true);
        router.post(
            route('expense_import.map.save', batch.id),
            {
                label,
                date: num(cols.date),
                merchant: num(cols.merchant),
                amount: mode === 'single' ? num(cols.amount) : null,
                debit: mode === 'split' ? num(cols.debit) : null,
                credit: mode === 'split' ? num(cols.credit) : null,
                currency: num(cols.currency),
                reference: num(cols.reference),
                fixed_currency: cols.currency === '' ? fixedCurrency : null,
                expenses_positive: mode === 'single' && expensesPositive,
            },
            { onFinish: () => setSaving(false) },
        );
    };

    const field = (f: Field, labelKey: string, optional = false) => (
        <div className="grid min-w-0 gap-2">
            <Label htmlFor={`map-${f}`}>{t(labelKey)}</Label>
            <Picker id={`map-${f}`} options={options(optional)} selected={[cols[f]]} onChange={set(f)} searchable={headers.length > 6} />
            {errors[f] && <p className="text-destructive text-xs">{errors[f]}</p>}
        </div>
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('expense_import.title'), href: route('expense_import.index') },
        { title: t('expense_import.map_title'), href: route('expense_import.map', batch.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('expense_import.map_title')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="min-w-0">
                    <h1 className="font-heading text-primary text-2xl">{t('expense_import.map_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('expense_import.map_sub')}</p>
                </div>

                <section className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <div className="grid gap-2">
                        <Label htmlFor="map-label">{t('expense_import.map_label')}</Label>
                        <Input
                            id="map-label"
                            value={label}
                            onChange={(e) => setLabel(e.target.value)}
                            placeholder={t('expense_import.map_label_ph')}
                            className="text-base md:text-sm"
                        />
                        {errors.label && <p className="text-destructive text-xs">{errors.label}</p>}
                    </div>
                    {field('date', 'expense_import.map_date')}
                    {field('merchant', 'expense_import.map_merchant')}
                    <div className="grid gap-2">
                        <Label>{t('expense_import.map_amount_mode')}</Label>
                        <Segmented
                            ariaLabel={t('expense_import.map_amount_mode')}
                            options={[
                                { value: 'single', label: t('expense_import.map_amount_single') },
                                { value: 'split', label: t('expense_import.map_amount_split') },
                            ]}
                            value={mode}
                            onChange={setMode}
                        />
                    </div>
                    {mode === 'single' ? (
                        <>
                            {field('amount', 'expense_import.map_amount')}
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={expensesPositive} onCheckedChange={(v) => setExpensesPositive(v === true)} />
                                {t('expense_import.map_expenses_positive')}
                            </label>
                        </>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('debit', 'expense_import.map_debit')}
                            {field('credit', 'expense_import.map_credit')}
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('currency', 'expense_import.map_currency', true)}
                        {cols.currency === '' && (
                            <div className="grid min-w-0 gap-2">
                                <Label htmlFor="map-fixed">{t('expense_import.map_fixed_currency')}</Label>
                                <Picker
                                    id="map-fixed"
                                    searchable={false}
                                    options={currencyOptions(currencies)}
                                    selected={[fixedCurrency]}
                                    onChange={([v]) => v && setFixedCurrency(v)}
                                />
                            </div>
                        )}
                    </div>
                    {field('reference', 'expense_import.map_reference', true)}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{t('expense_import.map_preview')}</h2>
                    {/* One line per column with its first values: reads on a phone without a sideways-scrolling table. */}
                    <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                        {headers.map((h, i) => (
                            <li key={i} className="bg-surface-card flex items-baseline gap-3 px-4 py-2.5">
                                <span className="text-primary w-28 shrink-0 truncate text-sm font-medium sm:w-40">{h || `#${i + 1}`}</span>
                                <span className="text-muted-foreground min-w-0 flex-1 truncate text-sm">
                                    {samples
                                        .map((row) => row[i] ?? '')
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button onClick={save} disabled={saving || !label.trim()}>
                        {t('expense_import.map_save')}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('expense_import.destroy', batch.id)} method="delete" as="button">
                            {t('expense_import.discard')}
                        </Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
