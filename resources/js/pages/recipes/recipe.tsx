import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Camera, Globe, Pencil, ShoppingCart, Trash2, Users } from 'lucide-react';
import { useRef, useState } from 'react';

import { GuestSheet, type GuestShareState } from '@/components/guest-sheet';
import { Picker } from '@/components/picker';
import { RecipeHeader, RecipeView } from '@/components/recipes/recipe-view';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { compressImage, makeThumbnail } from '@/lib/compress-image';
import { useLocale, useT } from '@/lib/i18n';
import { formatQty, scaleIngredient } from '@/lib/recipe-scale.js';
import { unitLabel, type Recipe } from '@/lib/recipes';
import { type BreadcrumbItem } from '@/types';

type Props = {
    recipe: Recipe;
    canEdit: boolean;
    canDelete: boolean;
    shoppingLists: { id: number; name: string }[] | null; // null = Shopping is off for the family
    share: ShareState;
    guestShare: GuestShareState;
};

export default function RecipePage({ recipe, canEdit, canDelete, shoppingLists, share, guestShare }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.recipes'), href: '/m/recipes' },
        { title: recipe.title, href: `/m/recipes/${recipe.id}` },
    ];
    const [sharing, setSharing] = useState(false);
    const [publicLink, setPublicLink] = useState(false);
    const [shopFactor, setShopFactor] = useState<number | null>(null); // open = the factor to add at
    const file = useRef<HTMLInputElement>(null);

    const uploadPhoto = async (f: File) => {
        // Compressed full image + a small thumbnail for the index cards (same as project covers).
        const photo = await compressImage(f, 1_200_000);
        const thumb = await makeThumbnail(f, 640);
        router.post(
            route('recipes.photo.upload', recipe.id),
            { photo, photo_thumb: thumb ?? undefined },
            { forceFormData: true, preserveScroll: true },
        );
    };
    const remove = () => {
        if (window.confirm(t('recipes.delete_confirm'))) router.delete(route('recipes.destroy', recipe.id));
    };

    const photo = recipe.photoUrl ? (
        <div className="relative">
            <img src={recipe.photoUrl} alt="" className="aspect-[16/10] w-full rounded-2xl object-cover" />
            {canEdit && (
                <div className="absolute right-3 bottom-3 flex gap-2">
                    <button
                        type="button"
                        onClick={() => file.current?.click()}
                        className="bg-background/85 text-primary rounded-full px-3 py-1.5 text-xs font-medium backdrop-blur"
                    >
                        {t('recipes.change_photo')}
                    </button>
                    <button
                        type="button"
                        onClick={() => router.delete(route('recipes.photo.delete', recipe.id), { preserveScroll: true })}
                        className="bg-background/85 text-primary rounded-full px-3 py-1.5 text-xs font-medium backdrop-blur"
                    >
                        {t('recipes.remove_photo')}
                    </button>
                </div>
            )}
        </div>
    ) : canEdit ? (
        <button
            type="button"
            onClick={() => file.current?.click()}
            className="border-border text-muted-foreground hover:border-accent flex aspect-[16/7] w-full flex-col items-center justify-center gap-2 rounded-2xl border border-dashed"
        >
            <Camera className="size-6" />
            <span className="text-sm">{t('recipes.add_photo')}</span>
        </button>
    ) : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={recipe.title} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-center justify-between gap-2">
                    <Link href="/m/recipes" className="text-muted-foreground hover:text-primary" aria-label={t('recipes.back')}>
                        <ArrowLeft className="size-5" />
                    </Link>
                    <div className="flex items-center gap-1.5">
                        <IconButton label={t('recipes.share')} onClick={() => setSharing(true)} icon={<Users className="size-4" />} />
                        <IconButton label={t('recipes.public_link')} onClick={() => setPublicLink(true)} icon={<Globe className="size-4" />} />
                        {canEdit && (
                            <IconButton
                                label={t('recipes.edit')}
                                onClick={() => router.visit(route('recipes.edit', recipe.id))}
                                icon={<Pencil className="size-4" />}
                            />
                        )}
                        {canDelete && <IconButton label={t('recipes.delete')} onClick={remove} icon={<Trash2 className="size-4" />} />}
                    </div>
                </div>

                <input
                    ref={file}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={(e) => e.target.files?.[0] && uploadPhoto(e.target.files[0])}
                />
                <RecipeHeader recipe={recipe} photo={photo} />

                <RecipeView
                    recipe={recipe}
                    shop={(factor) =>
                        shoppingLists && recipe.ingredients.some((i) => i.qty) ? (
                            <Button variant="outline" className="self-start" onClick={() => setShopFactor(factor)}>
                                <ShoppingCart className="size-4" /> {t('recipes.add_to_shopping')}
                            </Button>
                        ) : null
                    }
                />
            </div>

            <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={recipe.title} withLink={false} />
            <GuestSheet open={publicLink} onOpenChange={setPublicLink} guestShare={guestShare} entityName={recipe.title} people={false} />
            {shoppingLists && shopFactor !== null && (
                <ShoppingModal recipe={recipe} factor={shopFactor} lists={shoppingLists} onClose={() => setShopFactor(null)} />
            )}
        </AppLayout>
    );
}

function IconButton({ label, onClick, icon }: { label: string; onClick: () => void; icon: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border"
        >
            {icon}
        </button>
    );
}

// Pick a list, untick what you already have, add the rest at the current scale. Posts to Shopping's own
// bulk-add endpoint (same path as adding several items in Shopping: one notification, aisle guessing).
function ShoppingModal({
    recipe,
    factor,
    lists,
    onClose,
}: {
    recipe: Recipe;
    factor: number;
    lists: { id: number; name: string }[];
    onClose: () => void;
}) {
    const t = useT();
    const locale = useLocale();
    const lines = recipe.ingredients.map((ing, i) => ({ ing, i, s: scaleIngredient(ing, factor) })).filter(({ s }) => s.qty !== null);
    const [picked, setPicked] = useState<Set<number>>(new Set(lines.map((l) => l.i)));
    const [listId, setListId] = useState(lists[0]?.id ?? 0);
    const [added, setAdded] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const add = () => {
        const items = lines
            .filter((l) => picked.has(l.i))
            .map(({ ing, s }) => ({
                name: ing.name,
                quantity: `${formatQty(s.qty, s.unit, 'en-US').replace(/,/g, '')} ${unitLabel(t, s.unit)}`.trim(),
                note: recipe.title,
            }));
        if (!items.length) return;
        setBusy(true);
        router.post(
            route('shopping.items.bulk', listId),
            { items },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setAdded(lists.find((l) => l.id === listId)?.name ?? ''),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title={t('recipes.add_to_shopping')}
            description={factor === 1 ? t('recipes.as_written') : `×${Math.round(factor * 100) / 100}`}
            footer={
                added !== null ? (
                    <div className="flex w-full flex-col gap-2">
                        <p className="text-primary text-sm">{t('recipes.added_to', { list: added })}</p>
                        <div className="flex gap-2">
                            <Button variant="outline" className="flex-1" onClick={onClose}>
                                {t('recipes.done')}
                            </Button>
                            <Button className="flex-1" onClick={() => router.visit(`/m/shopping/lists/${listId}`)}>
                                {t('recipes.open_list')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button className="w-full" disabled={busy || picked.size === 0 || !listId} onClick={add}>
                        <ShoppingCart className="size-4" /> {t('recipes.add_n', { n: picked.size })}
                    </Button>
                )
            }
        >
            <div className="flex flex-col gap-3 pt-1">
                <Picker
                    options={lists.map((l) => ({ value: String(l.id), label: l.name }))}
                    selected={listId ? [String(listId)] : []}
                    onChange={(next) => next[0] && setListId(Number(next[0]))}
                    ariaLabel={t('recipes.list')}
                    fallbackIcon={ShoppingCart}
                />
                <p className="text-muted-foreground text-xs">{t('recipes.untick_have')}</p>
                <ul className="flex flex-col">
                    {lines.map(({ ing, i, s }) => (
                        <li key={i}>
                            <label className="flex cursor-pointer items-center gap-3 py-2">
                                <input
                                    type="checkbox"
                                    checked={picked.has(i)}
                                    onChange={() =>
                                        setPicked((p) => {
                                            const n = new Set(p);
                                            if (n.has(i)) n.delete(i);
                                            else n.add(i);
                                            return n;
                                        })
                                    }
                                    className="size-4 accent-[var(--accent-strong)]"
                                />
                                <span className="text-primary w-20 shrink-0 font-medium tabular-nums">
                                    {formatQty(s.qty, s.unit, locale)} {unitLabel(t, s.unit)}
                                </span>
                                <span className="min-w-0 flex-1 truncate">{ing.name}</span>
                            </label>
                        </li>
                    ))}
                </ul>
            </div>
        </ResponsiveModal>
    );
}
