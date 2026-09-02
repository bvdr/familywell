import { CategoryIcon } from '@/components/category-icon';
import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { catLabel as catLabelOf, categoryMap, type CategoryDef } from '@/lib/categories';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

// A category row carries its usage count so the UI can block deleting one that's still on spends.
type Category = CategoryDef & { id: number; inUse: number };

type Props = {
    currencies: string[];
    defaultCurrency: string | null;
    categories: Category[];
    canManage: boolean; // family admin? gates every write on this screen
};

export default function SpendingSettings({ currencies, defaultCurrency, categories, canManage }: Props) {
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
                    <select
                        value={currencyOpt.get('default', defaultCurrency ?? '')}
                        onChange={(e) => setCurrency(e.target.value)}
                        disabled={!canManage}
                        className="border-border bg-surface-card text-primary w-full rounded-lg border px-3 py-2 text-base disabled:opacity-60 md:max-w-xs md:text-sm"
                        aria-label={t('spending.default_currency')}
                    >
                        <option value="">{t('spending.currency_auto')}</option>
                        {currencies.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </select>
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
            </div>
        </AppLayout>
    );
}
