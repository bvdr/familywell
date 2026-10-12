import { CategoryIcon } from '@/components/category-icon';
import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { Picker } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { catLabel as catLabelOf, categoryMap, type CategoryDef } from '@/lib/categories';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Banknote, Plus, Sparkles, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

// A category row carries its usage count so the UI can block deleting one that's still on spends.
type Category = CategoryDef & { id: number; inUse: number };

type Props = {
    currencies: string[];
    defaultCurrency: string | null;
    categories: Category[];
    canManage: boolean; // family admin? gates every write on this screen
    aiCategorize: boolean; // TypeSafe key present => the bulk "sort Other" tool is available
};

// Bulk AI re-categorisation of expenses left in "Other". The server works in batches (cursor = last
// spend id) so we can show real progress; we just loop until it reports nothing left. A failed batch
// stops the loop with the progress kept: running again resumes, since only "Other" rows are touched.
function AiSort({ total }: { total: number }) {
    const t = useT();
    const [state, setState] = useState<'idle' | 'running' | 'done' | 'failed'>('idle');
    const [processed, setProcessed] = useState(0);
    const [moved, setMoved] = useState(0);

    const run = async () => {
        setState('running');
        setProcessed(0);
        setMoved(0);
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        let after: number | null = 0;
        let done = 0;
        let ok = 0;
        try {
            while (after !== null) {
                // Relative URL so the same-origin session cookie is sent (see shopping/list.tsx).
                const res = await fetch(route('spending.settings.recategorize', undefined, false), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ after }),
                });
                if (!res.ok) throw new Error(String(res.status));
                const batch: { processed: number; updated: number; next: number | null } = await res.json();
                done += batch.processed;
                ok += batch.updated;
                setProcessed(done);
                setMoved(ok);
                after = batch.next;
            }
            setState('done');
            router.reload({ only: ['categories'] }); // refresh the per-category usage counts
        } catch {
            setState('failed');
        }
    };

    const pct = total === 0 ? 100 : Math.min(100, Math.round((processed / total) * 100));

    return (
        <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
            <div>
                <h2 className="font-heading text-primary flex items-center gap-2 text-lg">
                    <Sparkles className="size-4" /> {t('spending.ai_sort_title')}
                </h2>
                <p className="text-muted-foreground text-sm">{t('spending.ai_sort_desc')}</p>
            </div>
            {state === 'running' || state === 'done' || state === 'failed' ? (
                <div className="flex flex-col gap-2">
                    <div
                        className="bg-muted h-2 w-full overflow-hidden rounded-full"
                        role="progressbar"
                        aria-valuenow={pct}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    >
                        <div className="bg-primary h-full rounded-full transition-[width] duration-300" style={{ width: `${pct}%` }} />
                    </div>
                    <p className="text-muted-foreground text-sm" aria-live="polite">
                        {state === 'running' && `${t('spending.ai_sort_running')} ${processed}/${total}`}
                        {state === 'done' && t('spending.ai_sort_done', { moved, total: processed })}
                        {state === 'failed' && t('spending.ai_sort_failed')}
                    </p>
                </div>
            ) : (
                <p className="text-muted-foreground text-sm">
                    {total === 0 ? t('spending.ai_sort_nothing') : t('spending.ai_sort_pending', { count: total })}
                </p>
            )}
            <div>
                <Button type="button" onClick={run} disabled={state === 'running' || (total === 0 && state === 'idle')}>
                    <Sparkles className="size-4" /> {t('spending.ai_sort_run')}
                </Button>
            </div>
        </div>
    );
}

export default function SpendingSettings({ currencies, defaultCurrency, categories, canManage, aiCategorize }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.spending'), href: '/m/spending' },
        { title: t('spending.settings'), href: route('spending.settings') },
    ];
    const catMap = categoryMap(categories);

    // Optimistic overlays so every control here reacts on tap: the currency select shows the new value,
    // a category's icon swaps, a deleted category row vanishes. Server props reconcile on reload.
    const currencyOpt = useOptimistic<string>();
    const iconOpt = useOptimistic<string | null>();
    const removedOpt = useOptimistic<boolean>();
    const visibleCategories = categories.filter((c) => !removedOpt.get(c.id, false));
    const settle = <K extends string | number>(o: { clear: (id: K) => void }, id: K) => ({
        onSuccess: () => o.clear(id),
        onError: () => o.clear(id),
    });

    // Default currency: '' is the "auto" option (server stores null and falls back).
    const setCurrency = (value: string) => {
        currencyOpt.set('default', value);
        router.patch(
            route('spending.settings.update'),
            { default_currency: value || null },
            { preserveScroll: true, preserveState: true, ...settle(currencyOpt, 'default') },
        );
    };

    const addForm = useForm({ name: '', icon: null as string | null });
    const submitCategory: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('spending.categories.store'), { preserveScroll: true, onSuccess: () => addForm.reset() });
    };

    // Persist a custom category's chosen icon immediately (the picker is the row's edit affordance). The
    // key is untouched, so existing spends keep resolving; null clears back to the generic tag fallback.
    const changeIcon = (c: Category, icon: string | null) => {
        iconOpt.set(c.id, icon);
        router.patch(route('spending.categories.update', c.id), { icon }, { preserveScroll: true, preserveState: true, ...settle(iconOpt, c.id) });
    };

    const removeCategory = (c: Category) => {
        if (c.inUse > 0) return; // in-use categories can't be deleted (they'd orphan spends)
        removedOpt.set(c.id, true);
        router.delete(route('spending.categories.destroy', c.id), { preserveScroll: true, preserveState: true, ...settle(removedOpt, c.id) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('spending.settings')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('spending.settings')}</h1>
                    <p className="text-muted-foreground text-sm">{t('spending.settings_sub')}</p>
                </div>

                {/* Default currency */}
                <div className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <div>
                        <h2 className="font-heading text-primary text-lg">{t('spending.default_currency')}</h2>
                        <p className="text-muted-foreground text-sm">{t('spending.default_currency_desc')}</p>
                    </div>
                    <div className="md:max-w-xs">
                        <Picker
                            options={[{ value: '', label: t('spending.currency_auto') }, ...currencies.map((c) => ({ value: c, label: c }))]}
                            selected={[currencyOpt.get('default', defaultCurrency ?? '')]}
                            onChange={([c]) => c !== undefined && setCurrency(c)}
                            disabled={!canManage}
                            fallbackIcon={Banknote}
                            ariaLabel={t('spending.default_currency')}
                        />
                    </div>
                </div>

                {/* Categories */}
                <div className="bg-surface-card flex flex-col gap-4 rounded-xl border p-5">
                    <div>
                        <h2 className="font-heading text-primary text-lg">{t('spending.categories')}</h2>
                        <p className="text-muted-foreground text-sm">{t('spending.categories_desc')}</p>
                    </div>

                    <ul className="flex flex-col divide-y">
                        {visibleCategories.map((c) => {
                            // Custom categories are icon-editable: the coloured square is itself an IconPicker
                            // trigger. Built-in categories keep their curated icon as a static (non-editable) square.
                            const editable = canManage && !c.isDefault;
                            const icon = iconOpt.get(c.id, c.icon);
                            return (
                                <li key={c.id} className="flex items-center justify-between gap-3 py-2.5">
                                    <div className="flex min-w-0 items-center gap-3">
                                        {editable ? (
                                            <IconPicker
                                                value={icon}
                                                onChange={(icon) => changeIcon(c, icon)}
                                                style={{ background: c.color }}
                                                className="size-8 rounded-lg border-transparent text-white hover:opacity-90"
                                                iconClassName="size-4 text-white"
                                                ariaLabel={t('spending.change_category_icon')}
                                            />
                                        ) : (
                                            <span
                                                className="flex size-8 shrink-0 items-center justify-center rounded-lg text-white"
                                                style={{ background: c.color }}
                                            >
                                                <CategoryIcon icon={c.icon} className="size-4" />
                                            </span>
                                        )}
                                        <div className="min-w-0">
                                            <div className="text-primary truncate text-sm font-medium">{catLabelOf(t, catMap, c.key)}</div>
                                            {c.inUse > 0 && (
                                                <div className="text-muted-foreground text-xs">
                                                    {c.inUse} {t(c.inUse === 1 ? 'spending.spend_one' : 'spending.spend_other')}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => removeCategory(c)}
                                            disabled={c.inUse > 0}
                                            title={c.inUse > 0 ? t('spending.category_in_use') : t('spending.delete_category')}
                                            aria-label={t('spending.delete_category')}
                                            className="text-muted-foreground hover:text-destructive flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    {canManage && (
                        <form onSubmit={submitCategory} className="flex flex-col gap-2 border-t pt-4">
                            <Label htmlFor="category-name">{t('spending.add_category')}</Label>
                            <div className="flex items-start gap-2">
                                {/* Optional icon for the new category; defaults to the generic tag when left unpicked. */}
                                <IconPicker
                                    value={addForm.data.icon}
                                    onChange={(icon) => addForm.setData('icon', icon)}
                                    ariaLabel={t('spending.change_category_icon')}
                                />
                                <div className="flex-1">
                                    <Input
                                        id="category-name"
                                        value={addForm.data.name}
                                        onChange={(e) => addForm.setData('name', e.target.value)}
                                        placeholder={t('spending.category_name_placeholder')}
                                        maxLength={40}
                                    />
                                    <InputError message={addForm.errors.name} className="mt-1" />
                                </div>
                                <Button type="submit" disabled={addForm.processing || addForm.data.name.trim() === ''}>
                                    <Plus className="size-4" /> {t('common.add')}
                                </Button>
                            </div>
                        </form>
                    )}
                </div>

                {/* Bulk AI re-categorisation: admins only, and only when a TypeSafe key is configured. */}
                {canManage && aiCategorize && <AiSort total={categories.find((c) => c.key === 'other')?.inUse ?? 0} />}
            </div>
        </AppLayout>
    );
}
