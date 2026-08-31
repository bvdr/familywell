import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

import { CategoryTile } from '@/components/inventory';
import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { byKey, catLabel, type InvCategory } from '@/lib/inventory';
import type { BreadcrumbItem } from '@/types';

// A category card carries its item tallies plus the DB id used by the manage endpoints.
type CatRow = InvCategory & { id: number; count: number; attention: number };

type Props = { categories: CatRow[]; canManage: boolean };

export default function InventoryCategories({ categories, canManage }: Props) {
    const t = useT();
    const catMap = byKey(categories);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.categories_title'), href: '/m/inventory/categories' },
    ];

    // Change a custom category's icon inline (the coloured tile IS the picker). null clears to the fallback.
    const changeIcon = (c: CatRow, icon: string | null) =>
        router.patch(route('inventory.categories.update', c.id), { icon }, { preserveScroll: true });

    const remove = (c: CatRow) => {
        if (c.count > 0) return; // in-use categories can't be deleted (they'd orphan items)
        router.delete(route('inventory.categories.destroy', c.id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.categories_title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <Link href="/m/inventory" className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <ArrowLeft className="size-4" /> {t('inventory.title')}
                </Link>

                <header>
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.categories_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('inventory.categories_subtitle')}</p>
                </header>

                {categories.length === 0 ? (
                    <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-10 text-center text-sm">{t('inventory.no_categories')}</p>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {categories.map((c) => {
                            const editable = canManage && !c.isDefault;
                            const subtitle = [
                                t('inventory.items_count', { count: c.count }),
                                c.attention > 0 ? t('inventory.attention_count', { count: c.attention }) : null,
                            ]
                                .filter(Boolean)
                                .join(' · ');
                            return (
                                <div key={c.key} className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-3">
                                    {editable ? (
                                        <IconPicker
                                            value={c.icon}
                                            onChange={(icon) => changeIcon(c, icon)}
                                            style={{ background: `color-mix(in srgb, ${c.color} 16%, transparent)`, color: c.color }}
                                            className="size-10 rounded-lg border-transparent"
                                            iconClassName="size-5"
                                            ariaLabel={t('inventory.change_icon')}
                                        />
                                    ) : (
                                        <CategoryTile category={c} />
                                    )}
                                    <Link href={`/m/inventory/items?category=${encodeURIComponent(c.key)}`} className="flex min-w-0 flex-1 items-center gap-2">
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-sm font-medium">{catLabel(t, c.key, catMap)}</span>
                                            <span className="text-muted-foreground block truncate text-xs">{subtitle}</span>
                                        </span>
                                        <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                    </Link>
                                    {editable && (
                                        <button
                                            type="button"
                                            onClick={() => remove(c)}
                                            disabled={c.count > 0}
                                            title={c.count > 0 ? t('inventory.category_in_use') : t('inventory.delete_category')}
                                            aria-label={t('inventory.delete_category')}
                                            className="text-muted-foreground hover:text-destructive flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {canManage ? (
                    <AddForm />
                ) : (
                    <p className="text-muted-foreground text-xs">{t('inventory.manage_hint_admins')}</p>
                )}
            </div>
        </AppLayout>
    );
}

// Add a custom category: an optional icon (defaults to the generic tag) + a name.
function AddForm() {
    const t = useT();
    const form = useForm<{ name: string; icon: string | null }>({ name: '', icon: null });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('inventory.categories.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <form onSubmit={submit} className="bg-surface-card flex flex-col gap-2 rounded-xl border p-4">
            <Label htmlFor="inv-cat-name">{t('inventory.add_category')}</Label>
            <div className="flex items-start gap-2">
                <IconPicker value={form.data.icon} onChange={(icon) => form.setData('icon', icon)} ariaLabel={t('inventory.change_icon')} />
                <div className="flex-1">
                    <Input
                        id="inv-cat-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder={t('inventory.category_name_placeholder')}
                        maxLength={40}
                        className="text-base md:text-sm"
                    />
                    <InputError message={form.errors.name} className="mt-1" />
                </div>
                <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                    <Plus className="size-4" /> {t('common.add')}
                </Button>
            </div>
        </form>
    );
}
