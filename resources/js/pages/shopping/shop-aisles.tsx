import { closestCenter, DndContext, MouseSensor, TouchSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head } from '@inertiajs/react';
import {
    Circle,
    CircleCheck,
    GripVertical,
    Hammer,
    LayoutTemplate,
    Pill,
    Plus,
    ShoppingBasket,
    SquareDashed,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';

import { SettingsPage, SettingsRow } from '@/components/settings-ui';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { aisleIcon } from '@/lib/shopping';
import { cn } from '@/lib/utils';

type Aisle = { id: number; slug: string; name: string; icon: string; items: number };
type Template = { key: string; label: string; names: string[] };
type Shop = { id: number; name: string; logo: string | null; template: string };

const TEMPLATE_ICON: Record<string, LucideIcon> = { grocery: ShoppingBasket, bricolage: Hammer, pharmacy: Pill, blank: SquareDashed };

// JSON calls to the existing aisle endpoints (they answer with the shop's aisles). CSRF from the XSRF cookie.
async function call(method: string, url: string, body?: object): Promise<{ aisles?: Omit<Aisle, 'items'>[] } | null> {
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const r = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return r.ok ? r.json() : null;
}

// A shop's aisles (design S1f): where it started from (tap to switch, S1g), its aisles in walking order (drag),
// how many items each holds, tap one to rename or remove it, add one at the end. Kaufland and Hornbach need
// different aisles, so each shop has its own. A new shop lands here with the starting-set sheet up (?pick=1).
export default function ShopAisles({
    shop,
    aisles: initial,
    templates,
    suggested,
    pick,
}: {
    shop: Shop;
    aisles: Aisle[];
    templates: Template[];
    suggested: string;
    pick: boolean;
}) {
    const t = useT();
    const [aisles, setAisles] = useState(initial);
    const [template, setTemplate] = useState(shop.template);
    const [choosing, setChoosing] = useState(pick);
    const [chosen, setChosen] = useState(pick ? suggested : shop.template);
    const [editing, setEditing] = useState<Aisle | 'new' | null>(null);
    const [name, setName] = useState('');
    const base = `/m/shopping/shops/${shop.id}/aisles`;
    // Server answers drop the counts: keep each aisle's count by slug (a new or renamed aisle starts at its own).
    const withCounts = (next: Omit<Aisle, 'items'>[]) => next.map((a) => ({ ...a, items: aisles.find((x) => x.slug === a.slug)?.items ?? 0 }));

    const commit = (next: Aisle[]) => {
        setAisles(next);
        void call('POST', `${base}/reorder`, { slugs: next.map((a) => a.slug) });
    };
    const move = (from: number, to: number) => to >= 0 && to < aisles.length && commit(arrayMove(aisles, from, to));
    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
    );
    const onDragEnd = ({ active, over }: DragEndEvent) =>
        over &&
        active.id !== over.id &&
        move(
            aisles.findIndex((a) => a.id === active.id),
            aisles.findIndex((a) => a.id === over.id),
        );

    const useTemplate = async () => {
        const r = await call('POST', `${base}/template`, { template: chosen });
        if (r?.aisles) {
            setAisles(withCounts(r.aisles));
            setTemplate(chosen);
        }
        setChoosing(false);
    };
    const saveAisle = async () => {
        const n = name.trim();
        if (!n || !editing) return;
        const r =
            editing === 'new'
                ? await call('POST', base, { name: n, icon: 'basket' })
                : await call('PATCH', `/m/shopping/aisles/${editing.id}`, { name: n });
        if (r?.aisles) setAisles(withCounts(r.aisles));
        setEditing(null);
    };
    const removeAisle = async () => {
        if (!editing || editing === 'new') return;
        const r = await call('DELETE', `/m/shopping/aisles/${editing.id}`);
        if (r?.aisles) setAisles(withCounts(r.aisles));
        setEditing(null);
    };
    const open = (a: Aisle | 'new') => {
        setName(a === 'new' ? '' : a.name);
        setEditing(a);
    };
    const label = (key: string) => templates.find((x) => x.key === key)?.label ?? key;
    const eyebrow = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase';

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('shopping.shop_aisles', { name: shop.name }), href: base }]}>
            <Head title={t('shopping.shop_aisles', { name: shop.name })} />
            <SettingsPage back={{ href: `/m/shopping/shops/${shop.id}`, label: shop.name }} title={t('shopping.shop_aisles', { name: shop.name })}>
                <div className="bg-surface-card overflow-hidden rounded-[22px] border">
                    <SettingsRow
                        icon={LayoutTemplate}
                        title={t('shopping.started_from')}
                        value={label(template)}
                        onClick={() => {
                            setChosen(template);
                            setChoosing(true);
                        }}
                    />
                </div>

                <div className="flex flex-col gap-2">
                    <h2 className={eyebrow}>{t('shopping.drag_to_reorder')}</h2>
                    <div className="bg-surface-card overflow-hidden rounded-[22px] border">
                        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                            <SortableContext items={aisles.map((a) => a.id)} strategy={verticalListSortingStrategy}>
                                {aisles.map((a, i) => (
                                    <AisleRow key={a.id} aisle={a} onOpen={() => open(a)} onMove={(by) => move(i, i + by)} />
                                ))}
                            </SortableContext>
                        </DndContext>
                        <button type="button" onClick={() => open('new')} className="flex w-full items-center gap-3 py-3 pr-4 pl-[60px] text-left">
                            <span className="border-border text-primary flex size-10 shrink-0 items-center justify-center rounded-xl border bg-white">
                                <Plus className="size-5" />
                            </span>
                            <span className="text-primary text-[17px] font-semibold">{t('shopping.add_an_aisle')}</span>
                        </button>
                    </div>
                    <p className="text-muted-foreground px-2 text-sm">{t('shopping.aisles_hint')}</p>
                </div>
            </SettingsPage>

            {/* S1g: the starting set of aisles. Suggested from the shop's name; using one replaces the aisles. */}
            <ResponsiveModal
                open={choosing}
                onOpenChange={setChoosing}
                title={t('shopping.aisles_for', { name: shop.name })}
                footer={
                    <button
                        type="button"
                        onClick={useTemplate}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold"
                    >
                        {t('shopping.use_template', { name: label(chosen) })}
                    </button>
                }
            >
                <div className="flex flex-col gap-3">
                    <p className="text-muted-foreground text-base">{t('shopping.pick_starting_set')}</p>
                    {templates.map((tp) => {
                        const Icon = TEMPLATE_ICON[tp.key] ?? ShoppingBasket;
                        const on = chosen === tp.key;
                        const preview =
                            tp.key === 'blank'
                                ? t('shopping.template_blank_sub')
                                : tp.names.slice(0, 3).join(', ') + (tp.names.length > 3 ? ` +${tp.names.length - 3}` : '');
                        return (
                            <button
                                key={tp.key}
                                type="button"
                                onClick={() => setChosen(tp.key)}
                                aria-pressed={on}
                                className={cn(
                                    'flex items-center gap-3 rounded-2xl border p-3 text-left',
                                    on ? 'border-primary bg-accent-strong/40 border-2' : 'bg-surface-card',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-11 shrink-0 items-center justify-center rounded-xl',
                                        on ? 'bg-accent-strong' : 'bg-surface',
                                    )}
                                >
                                    <Icon className="text-primary size-5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="text-primary flex flex-wrap items-center gap-2 text-[17px] font-semibold">
                                        {tp.label}
                                        {tp.key === suggested && (
                                            <span className="bg-primary text-accent-strong rounded-full px-2 py-0.5 text-xs font-bold">
                                                {t('shopping.suggested')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground block text-sm">{preview}</span>
                                </span>
                                {on ? (
                                    <CircleCheck className="text-primary size-6 shrink-0" />
                                ) : (
                                    <Circle className="text-muted-foreground size-6 shrink-0" />
                                )}
                            </button>
                        );
                    })}
                </div>
            </ResponsiveModal>

            {/* Rename (or remove) an aisle, or name a new one. */}
            <ResponsiveModal
                open={!!editing}
                onOpenChange={(o) => !o && setEditing(null)}
                title={editing === 'new' ? t('shopping.add_an_aisle') : t('shopping.rename_aisle')}
                footer={
                    <button
                        type="button"
                        onClick={saveAisle}
                        disabled={!name.trim()}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('common.save')}
                    </button>
                }
            >
                <div className="flex flex-col gap-4">
                    <Input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && saveAisle()}
                        aria-label={t('shopping.aisle_name')}
                        autoComplete="off"
                        className="text-base"
                    />
                    {editing && editing !== 'new' && (
                        <button
                            type="button"
                            onClick={removeAisle}
                            className="text-destructive flex min-h-11 items-center gap-2 self-start text-base font-semibold"
                        >
                            <Trash2 className="size-5" />
                            {t('shopping.remove_aisle')}
                        </button>
                    )}
                </div>
            </ResponsiveModal>
        </AppLayout>
    );
}

function AisleRow({ aisle, onOpen, onMove }: { aisle: Aisle; onOpen: () => void; onMove: (by: -1 | 1) => void }) {
    const t = useT();
    const Icon = aisleIcon(aisle.icon);
    const { listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: aisle.id });
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
                aria-label={t('shopping.move_shop', { name: aisle.name })}
                className="text-muted-foreground flex size-11 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg active:cursor-grabbing"
            >
                <GripVertical className="size-5" />
            </button>
            <button type="button" onClick={onOpen} className="flex min-w-0 flex-1 items-center gap-3 py-3 pr-4 text-left">
                <span className="bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <Icon className="size-5" />
                </span>
                <span className="text-primary min-w-0 flex-1 truncate text-[17px] font-semibold">{aisle.name}</span>
                <span className="text-muted-foreground shrink-0 text-sm">{t('shopping.n_items', { count: aisle.items })}</span>
            </button>
        </div>
    );
}
