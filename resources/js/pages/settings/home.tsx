import { closestCenter, DndContext, MouseSensor, TouchSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head } from '@inertiajs/react';
import { Cake, CalendarDays, GripVertical, History, Inbox, LayoutGrid, ListChecks, RotateCcw, Star, Wallet, type LucideIcon } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';

import { usePinned } from '@/components/pin-modules';
import { SettingsGroup, SettingsPage, SettingsRow, SettingsSwitch } from '@/components/settings-ui';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { cn } from '@/lib/utils';

// Home dashboard (design M6b): each person arranges their own Home. Drag the handle (or focus it and use the arrow
// keys) to reorder, flip the switch to show or hide a section. Saved per person (users.home_layout via
// PATCH /settings/home); the server only lists sections this family has (a module that is off hides its section).

type Section = { id: string; on: boolean };

// Icon per section id, in the default order (the same order as HomeLayout::SECTIONS on the server, so "Reset to
// default order" can sort locally and the screen doesn't wait for the save).
const ICONS: Record<string, LucideIcon> = {
    important: Star,
    birthdays: Cake,
    today: ListChecks,
    week: CalendarDays,
    anytime: Inbox,
    money: Wallet,
    modules: LayoutGrid,
    recent: History,
};
const DEFAULT = Object.keys(ICONS);

export default function HomeDashboard({ sections, family }: { sections: Section[]; family: string | null }) {
    const t = useT();
    const pinned = usePinned();
    const [items, setItems] = useState(sections);

    // Optimistic: the list changes at once and the save runs in the background (lib/save). Every id came from the
    // server, so the save has nothing to reject.
    const commit = (next: Section[]) => {
        setItems(next);
        void save('patch', '/settings/home', { order: next.map((s) => s.id), hidden: next.filter((s) => !s.on).map((s) => s.id) });
    };
    const move = (from: number, to: number) => to >= 0 && to < items.length && commit(arrayMove(items, from, to));

    // The whole card picks up, not only the handle (on a phone people press the title; iOS then selected the text).
    // Touch: press and hold ~0.2s, so a swipe over the cards still scrolls the page. Mouse: a short move.
    const sensors = useSensors(
        useSensor(MouseSensor, { activationConstraint: { distance: 4 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 200, tolerance: 8 } }),
    );
    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) return;
        move(
            items.findIndex((s) => s.id === active.id),
            items.findIndex((s) => s.id === over.id),
        );
    };

    const sub = (id: string) =>
        id === 'modules'
            ? pinned
                ? t('settings_home.modules_sub_pinned', { count: pinned.length })
                : t('settings_home.modules_sub_all')
            : t(`settings_home.${id}_sub`);

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('settings_hub.home_dashboard'), href: '/settings/home' }]}>
            <Head title={t('settings_hub.home_dashboard')} />
            <SettingsPage
                back={{ href: '/settings', label: t('settings_hub.me') }}
                title={t('settings_hub.home_dashboard')}
                lede={family ? t('settings_home.lede', { family }) : t('settings_home.lede_no_family')}
            >
                <SettingsGroup label={t('settings_home.sections')} aside={t('settings_home.drag_to_reorder')}>
                    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                        <SortableContext items={items.map((s) => s.id)} strategy={verticalListSortingStrategy}>
                            {items.map((s, i) => (
                                <SortableRow
                                    key={s.id}
                                    section={s}
                                    title={t(`settings_home.${s.id}`)}
                                    sub={sub(s.id)}
                                    onMove={(by) => move(i, i + by)}
                                    onToggle={() => commit(items.map((x) => (x.id === s.id ? { ...x, on: !x.on } : x)))}
                                />
                            ))}
                        </SortableContext>
                    </DndContext>
                </SettingsGroup>

                <button
                    type="button"
                    onClick={() => commit([...items].sort((a, b) => DEFAULT.indexOf(a.id) - DEFAULT.indexOf(b.id)))}
                    className="text-primary inline-flex min-h-11 items-center gap-2.5 self-start px-2 text-base font-semibold"
                >
                    <RotateCcw className="size-5" />
                    {t('settings_home.reset')}
                </button>
            </SettingsPage>
        </AppLayout>
    );
}

function SortableRow({
    section,
    title,
    sub,
    onMove,
    onToggle,
}: {
    section: Section;
    title: string;
    sub: string;
    onMove: (by: -1 | 1) => void;
    onToggle: () => void;
}) {
    const t = useT();
    // Pointer listeners on the whole card (see the sensors). No dnd-kit keyboard sensor: it announces in English, so
    // arrow keys on the handle move the row directly instead (fully translated, focus stays on the handle).
    const { listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: section.id });
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
            // No text selection or iOS callout on a long press: that press is how a card is picked up.
            className={cn(
                'bg-surface-card relative flex items-center border-b pl-1 select-none [-webkit-touch-callout:none] last:border-b-0',
                isDragging && 'z-10 shadow-lg',
            )}
        >
            <button
                type="button"
                ref={setActivatorNodeRef}
                onKeyDown={onKeyDown}
                aria-label={t('settings_home.move', { name: title })}
                className="text-muted-foreground flex size-11 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg active:cursor-grabbing"
            >
                <GripVertical className="size-5" />
            </button>
            <SettingsRow
                icon={ICONS[section.id]}
                tile
                title={title}
                sub={sub}
                muted={!section.on}
                className="min-w-0 flex-1 border-b-0 pl-1"
                right={<SettingsSwitch on={section.on} onToggle={onToggle} label={title} />}
            />
        </div>
    );
}
