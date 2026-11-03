import { closestCenter, DndContext, MouseSensor, TouchSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, GripVertical, Plus } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';

import { SettingsPage } from '@/components/settings-ui';
import { ShopLogo } from '@/components/shopping/shop-logo';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { cn } from '@/lib/utils';

type Shop = { id: number; name: string; logo: string | null; lists: number; isDefault: boolean };

// Your shops (design S1e), from Shopping settings: every shop with its logo and how many lists use it. Drag sets the
// order shops show up in (the Shops tab, filters, shop mode); tap opens the shop (its name, aisles). Add a shop:
// name or website; the logo comes from the name (Modules\Shopping\ShopBrands).
export default function YourShops({ shops: initial }: { shops: Shop[] }) {
    const t = useT();
    const [shops, setShops] = useState(initial);
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');
    const commit = (next: Shop[]) => {
        setShops(next);
        void save('post', route('shopping.shops.reorder'), { ids: next.map((s) => s.id) });
    };
    const move = (from: number, to: number) => to >= 0 && to < shops.length && commit(arrayMove(shops, from, to));
    // Same pick-up as Home dashboard's order: hold ~0.2 s on touch so a swipe still scrolls; a short move with a mouse.
    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
    );
    const onDragEnd = ({ active, over }: DragEndEvent) =>
        over &&
        active.id !== over.id &&
        move(
            shops.findIndex((s) => s.id === active.id),
            shops.findIndex((s) => s.id === over.id),
        );
    const add = () => {
        const n = name.trim();
        if (!n) return;
        router.post(
            route('shopping.shops.store'),
            { name: n },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    setShops((page.props as unknown as { shops: Shop[] }).shops);
                    setName('');
                    setAdding(false);
                },
            },
        );
    };
    const sub = (s: Shop) =>
        [s.isDefault && t('shopping.default'), s.lists ? t('shopping.n_lists', { count: s.lists }) : t('shopping.not_on_any_list')]
            .filter(Boolean)
            .join(' · ');

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('shopping.your_shops'), href: '/m/shopping/settings/shops' }]}>
            <Head title={t('shopping.your_shops')} />
            <SettingsPage back={{ href: '/m/shopping/settings', label: t('shopping.settings_short') }} title={t('shopping.your_shops')}>
                <div className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('shopping.drag_to_reorder')}
                    </h2>
                    <div className="bg-surface-card overflow-hidden rounded-[22px] border">
                        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                            <SortableContext items={shops.map((s) => s.id)} strategy={verticalListSortingStrategy}>
                                {shops.map((s, i) => (
                                    <ShopRow key={s.id} shop={s} sub={sub(s)} onMove={(by) => move(i, i + by)} />
                                ))}
                            </SortableContext>
                        </DndContext>
                        <button
                            type="button"
                            onClick={() => setAdding(true)}
                            className="flex w-full items-center gap-3 px-4 py-3 pl-[60px] text-left"
                        >
                            <span className="border-border text-primary flex size-10 shrink-0 items-center justify-center rounded-xl border bg-white">
                                <Plus className="size-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="text-primary block text-[17px] font-semibold">{t('shopping.add_a_shop')}</span>
                                <span className="text-muted-foreground block text-sm">{t('shopping.add_a_shop_sub')}</span>
                            </span>
                        </button>
                    </div>
                    <p className="text-muted-foreground px-2 text-sm">{t('shopping.remove_shop_hint')}</p>
                </div>
            </SettingsPage>

            <ResponsiveModal
                open={adding}
                onOpenChange={setAdding}
                title={t('shopping.add_a_shop')}
                footer={
                    <button
                        type="button"
                        onClick={add}
                        disabled={!name.trim()}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('shopping.add_a_shop')}
                    </button>
                }
            >
                <Input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && add()}
                    placeholder={t('shopping.add_a_shop_sub')}
                    autoComplete="off"
                    className="text-base"
                />
            </ResponsiveModal>
        </AppLayout>
    );
}

function ShopRow({ shop, sub, onMove }: { shop: Shop; sub: string; onMove: (by: -1 | 1) => void }) {
    const t = useT();
    const { listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: shop.id });
    // Arrow keys on the handle move the row (dnd-kit's keyboard sensor announces in English only).
    const onKeyDown = (e: KeyboardEvent) => {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            onMove(e.key === 'ArrowUp' ? -1 : 1);
        }
    };
    return (
        <div
            ref={setNodeRef}
            {...listeners}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            className={cn(
                'bg-surface-card relative flex items-center border-b pl-1 select-none [-webkit-touch-callout:none]',
                isDragging && 'z-10 shadow-lg',
            )}
        >
            <button
                type="button"
                ref={setActivatorNodeRef}
                onKeyDown={onKeyDown}
                aria-label={t('shopping.move_shop', { name: shop.name })}
                className="text-muted-foreground flex size-11 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg active:cursor-grabbing"
            >
                <GripVertical className="size-5" />
            </button>
            <Link href={`/m/shopping/shops/${shop.id}`} className="flex min-w-0 flex-1 items-center gap-3 py-3 pr-4">
                <ShopLogo name={shop.name} logo={shop.logo} className="size-10 rounded-xl" />
                <span className="min-w-0 flex-1">
                    <span className="text-primary block truncate text-[17px] font-semibold">{shop.name}</span>
                    <span className="text-muted-foreground block truncate text-sm">{sub}</span>
                </span>
                <ChevronRight className="text-muted-foreground size-5 shrink-0" />
            </Link>
        </div>
    );
}
