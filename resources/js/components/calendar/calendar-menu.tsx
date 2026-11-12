import { router } from '@inertiajs/react';
import {
    Cake,
    CalendarDays,
    CalendarPlus,
    ClipboardPaste,
    Copy,
    ExternalLink,
    Link2,
    ListChecks,
    Pencil,
    Plane,
    Plus,
    Sun,
    Tag,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { stripe } from '@/components/calendar/agenda';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuShortcut,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { openAdd } from '@/lib/add-action';
import { daysBetween, itemKey, parseDay, type CalCategory, type CalItem } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';

// What a right-click landed on: a thing (a bar, a row, a card: anything carrying data-cal-item) or an empty spot on a
// day (anything carrying data-day). Neither (a gap between bars): only the menu's always-there middle block.
type Target = { item: CalItem } | { day: string } | null;

export type CalendarMenuActions = {
    newEvent: (day: string) => void;
    trip: (day: string) => void;
    todo: ((day: string) => void) | null; // null: Tasks is off for this family
    birthdays: boolean; // Birthdays is on: "Birthday on <day>" opens its add sheet with that date
    openDay: (day: string) => void;
    today: () => void;
    edit: (item: CalItem) => void;
    duplicate: (item: CalItem) => void;
};

// ⌘ on a Mac, Ctrl elsewhere: how the shortcut column writes the modifier.
const MOD = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘' : 'Ctrl ';

// The calendar's right-click menu (design 04b D3 / D4, "Right-click is the + that already knows where you are"). Three
// blocks: about what you clicked (an empty day: new event / to-do / birthday / trip on that date; a thing: open, edit,
// category, duplicate, copy link), then Add anything + Paste (the same in every menu), then the day or the delete.
// Every row's letter works while the menu is open (data-key, see onKeyDown); Enter stays Radix's (the focused row). Mouse only: touch screens keep their
// long-press as it was (the trigger is disabled for a coarse pointer).
// ponytail: the design's "Hide <category> on the timeline" needs a hide-list next to the single-choice category filter; left out.
export function CalendarMenu({
    children,
    items,
    find,
    categories,
    actions,
    canPaste,
}: {
    children: React.ReactNode;
    items: CalItem[];
    find?: (key: string) => CalItem | undefined; // things outside `items` (the timeline loads more as it scrolls)
    categories: CalCategory[];
    actions: CalendarMenuActions;
    canPaste: boolean; // Just say it is on (Paste reads the clipboard into it)
}) {
    const t = useT();
    const [target, setTarget] = useState<Target>(null);
    const byKey = useMemo(() => new Map(items.map((i) => [itemKey(i), i])), [items]);
    const [touch] = useState(() => typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches);

    const onContextMenu = (e: React.MouseEvent) => {
        const el = e.target as HTMLElement;
        const key = el.closest<HTMLElement>('[data-cal-item]')?.dataset.calItem ?? '';
        const item = byKey.get(key) ?? find?.(key);
        const day = el.closest<HTMLElement>('[data-day]')?.dataset.day;
        setTarget(item ? { item } : day ? { day } : null);
    };
    // A row's letter (or ⌘D, ⌘L, Delete, ⇧T) clicks that row, which runs it and closes the menu like a click does.
    const onKeyDown = (e: React.KeyboardEvent) => {
        const combo = `${e.metaKey || e.ctrlKey ? 'mod+' : ''}${e.shiftKey ? 'shift+' : ''}${e.key.toLowerCase()}`;
        const row = (e.currentTarget as HTMLElement).querySelector<HTMLElement>(`[data-key="${combo === 'backspace' ? 'delete' : combo}"]`);
        if (!row) return;
        e.preventDefault(); // before Radix's typeahead, and the browser's ⌘D bookmark / ⌘L address bar
        row.click();
    };

    const short = (d: string) => parseDay(d).toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
    const long = (d: string) => parseDay(d).toLocaleDateString(appLocale(), { weekday: 'short', month: 'short', day: 'numeric' });
    const row = (key: string, icon: React.ReactNode, label: string, run: () => void, shortcut?: string) => (
        <ContextMenuItem data-key={key} onSelect={run}>
            {icon}
            {label}
            {shortcut && <ContextMenuShortcut>{shortcut}</ContextMenuShortcut>}
        </ContextMenuItem>
    );
    const header = (text: string) => (
        <ContextMenuLabel className="text-muted-foreground max-w-72 truncate font-mono text-[11px] font-semibold tracking-widest uppercase">
            {text}
        </ContextMenuLabel>
    );

    const always = (
        <>
            {row('n', <Plus />, t('calendar.menu_add_anything'), () => openAdd({ sheet: true }), 'N')}
            {canPaste && row('mod+v', <ClipboardPaste />, t('calendar.menu_paste'), () => openAdd({ paste: true }), `${MOD}V`)}
        </>
    );

    let body: React.ReactNode;
    if (target && 'item' in target) {
        const i = target.item;
        const own = i.source === 'calendar' && i.kind === 'event'; // this module's events; the rest belong to other modules
        const total = daysBetween(i.start, i.end) + 1;
        body = (
            <>
                {header(`${i.title} · ${total > 1 ? t('calendar.days', { count: total }) : long(i.start)}`)}
                {row('', <ExternalLink />, t('calendar.menu_open'), () => router.visit(i.href))}
                {own && i.editable && row('e', <Pencil />, t('calendar.menu_edit'), () => actions.edit(i), 'E')}
                {own && i.editable && (
                    <ContextMenuSub>
                        <ContextMenuSubTrigger>
                            <Tag />
                            {t('calendar.menu_category')}
                        </ContextMenuSubTrigger>
                        <ContextMenuSubContent>
                            {categories.map((c) => (
                                <ContextMenuItem
                                    key={c.slug}
                                    // Same rule as the event sheet's chips: the category brings its colour; picking the
                                    // current one again takes it off.
                                    onSelect={() =>
                                        router.patch(
                                            `/m/calendar/events/${i.id}`,
                                            i.category === c.slug
                                                ? { category: null, color: i.color === c.color ? null : i.color }
                                                : { category: c.slug, color: c.color },
                                            { preserveScroll: true, preserveState: true },
                                        )
                                    }
                                    className={i.category === c.slug ? 'font-semibold' : ''}
                                >
                                    <span className={`size-2 shrink-0 rounded-full ${stripe(c.color)}`} />
                                    {c.name}
                                </ContextMenuItem>
                            ))}
                            <ContextMenuSeparator />
                            {/* A new category is made inside the event sheet (C14), which then puts the event in it. */}
                            {row('', <Plus />, t('calendar.menu_new_category'), () => actions.edit(i))}
                        </ContextMenuSubContent>
                    </ContextMenuSub>
                )}
                {own && row('mod+d', <Copy />, t('calendar.menu_duplicate'), () => actions.duplicate(i), `${MOD}D`)}
                {row(
                    'mod+l',
                    <Link2 />,
                    t('calendar.menu_copy_link'),
                    () => void navigator.clipboard?.writeText(window.location.origin + i.href),
                    `${MOD}L`,
                )}
                <ContextMenuSeparator />
                {always}
                {own && i.deletable && (
                    <>
                        <ContextMenuSeparator />
                        <ContextMenuItem
                            data-key="delete"
                            onSelect={() =>
                                confirm(t('calendar.delete_confirm')) && router.delete(`/m/calendar/events/${i.id}`, { preserveScroll: true })
                            }
                            className="text-destructive focus:text-destructive"
                        >
                            <Trash2 />
                            {t('calendar.menu_delete')}
                            <ContextMenuShortcut>Del</ContextMenuShortcut>
                        </ContextMenuItem>
                    </>
                )}
            </>
        );
    } else if (target) {
        const d = target.day;
        body = (
            <>
                {header(long(d))}
                {row('e', <CalendarPlus />, t('calendar.menu_new_event', { date: short(d) }), () => actions.newEvent(d), 'E')}
                {actions.todo && row('t', <ListChecks />, t('calendar.menu_todo', { date: short(d) }), () => actions.todo?.(d), 'T')}
                {actions.birthdays &&
                    row('b', <Cake />, t('calendar.menu_birthday', { date: short(d) }), () => router.visit(`/m/birthdays?date=${d.slice(5)}`), 'B')}
                {row('p', <Plane />, t('calendar.menu_trip', { date: short(d) }), () => actions.trip(d), 'P')}
                <ContextMenuSeparator />
                {always}
                <ContextMenuSeparator />
                {row('', <CalendarDays />, t('calendar.menu_open_day'), () => actions.openDay(d))}
                {row('shift+t', <Sun />, t('calendar.menu_today'), actions.today, '⇧T')}
            </>
        );
    } else {
        body = (
            <>
                {always}
                <ContextMenuSeparator />
                {row('shift+t', <Sun />, t('calendar.menu_today'), actions.today, '⇧T')}
            </>
        );
    }

    return (
        // Not modal: a modal menu sets pointer-events:none on <body>, and when a row opens a sheet (New event, To-do,
        // Edit) the menu's cleanup and the sheet's own raced, leaving the page unclickable after the sheet closed.
        <ContextMenu modal={false}>
            <ContextMenuTrigger asChild disabled={touch} onContextMenu={onContextMenu}>
                <div>{children}</div>
            </ContextMenuTrigger>
            <ContextMenuContent className="min-w-60" onKeyDown={onKeyDown}>
                {body}
            </ContextMenuContent>
        </ContextMenu>
    );
}
