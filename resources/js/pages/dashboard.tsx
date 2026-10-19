import { Confetti } from '@/components/confetti';
import { LucideIcon as DynamicLucideIcon } from '@/components/lucide-icon';
import AppLayout from '@/layouts/app-layout';
import { useLocale, useT, type TFn } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { money } from '@/lib/money';
import { save } from '@/lib/save';
import { listColor as shoppingListColor, listIcon as shoppingListIcon } from '@/lib/shopping';
import { listColor as taskListColor, listIcon as taskListIcon } from '@/lib/tasks';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Blocks,
    CalendarDays,
    Check,
    ChefHat,
    FolderKanban,
    ListChecks,
    Plus,
    Settings,
    ShoppingCart,
    StickyNote,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

// A recently-opened entity ("Pick up where you left off"). visitedAt drives the order server-side.
// `category` is the module id (groups the cards); `icon`/`color` are the entity's own (a spend event's
// lucide key, a list's icon slug + colour swatch), so the card shows what the user picked.
type DashItem = { title: string; subtitle: string; href: string; icon: string; category?: string; color?: string | null };
// A dated leaf item (a task / shopping item due on a day) for the Today / This Week sections.
// toggleUrl + toggleBody: PATCH toggleBody (e.g. { done: true }) marks it done/bought, and it drops off on the reload.
// Each source names its own field (shopping says `bought`), so the dashboard never guesses and never sends a "flip".
// Both are absent for display-only rows (e.g. calendar events: they aren't "done").
type AgendaItem = DashItem & { dueDate: string; toggleUrl?: string; toggleBody?: Record<string, boolean> };
// Unique per row: a repeating event recurs under one href+title, and two to-dos of one event share href and
// maybe title, so the date and the per-task toggle URL go into the key too.
const agendaKey = (it: AgendaItem) => `${it.toggleUrl ?? it.href}-${it.title}-${it.dueDate}`;

// Map a provider's icon key to a lucide icon; fall back to a neutral one.
const ICONS: Record<string, LucideIcon> = {
    wallet: Wallet,
    'shopping-cart': ShoppingCart,
    'folder-kanban': FolderKanban,
    'list-checks': ListChecks,
    'sticky-note': StickyNote,
    'chef-hat': ChefHat,
    calendar: CalendarDays,
};
const iconFor = (key: string) => ICONS[key] ?? Blocks;

// The recent items are grouped by module on the dashboard: module id -> nav translation key for the
// group header. Older providers that only send an icon key still group by that key.
const CATEGORY: Record<string, string> = {
    tasks: 'nav.tasks',
    shopping: 'nav.shopping',
    spending: 'nav.spending',
    projects: 'nav.projects',
    notes: 'nav.notes',
    recipes: 'nav.recipes',
    'list-checks': 'nav.tasks',
    'shopping-cart': 'nav.shopping',
    wallet: 'nav.spending',
    'folder-kanban': 'nav.projects',
    'sticky-note': 'nav.notes',
};
const categoryOf = (it: DashItem) => CATEGORY[it.category ?? ''] ?? CATEGORY[it.icon] ?? 'Other';

// The card's icon tile: the entity's own icon + colour, resolved with the same helpers the module's
// own list page uses (so a shopping list looks the same here as on /m/shopping). Spend events store a
// lucide key; task/shopping lists store a slug + swatch. Everything else gets the neutral tile.
function ItemTile({ it }: { it: DashItem }) {
    const base = 'mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-lg';
    if (it.category === 'shopping') {
        const Icon = shoppingListIcon(it.icon);
        return (
            <span className={`${base} ${shoppingListColor(it.color ?? '')}`}>
                <Icon className="size-5" />
            </span>
        );
    }
    if (it.category === 'tasks') {
        const Icon = taskListIcon(it.icon);
        return (
            <span className={`${base} ${taskListColor(it.color ?? '')}`}>
                <Icon className="size-5" />
            </span>
        );
    }
    if (it.category === 'spending') {
        return (
            <span className={`${base} bg-tile text-tile-foreground`}>
                <DynamicLucideIcon name={it.icon} className="size-5" />
            </span>
        );
    }
    const Icon = iconFor(it.icon);
    return (
        <span className={`${base} bg-tile text-tile-foreground`}>
            <Icon className="size-5" />
        </span>
    );
}

// Group recent items by category, preserving the order the categories first appear. Since `recent`
// arrives most-recent-first, the category you opened most recently leads.
function groupRecent(recent: DashItem[]): { category: string; items: DashItem[] }[] {
    const groups: { category: string; items: DashItem[] }[] = [];
    const byCat = new Map<string, { category: string; items: DashItem[] }>();
    for (const it of recent) {
        const cat = categoryOf(it);
        let g = byCat.get(cat);
        if (!g) {
            g = { category: cat, items: [] };
            byCat.set(cat, g);
            groups.push(g);
        }
        g.items.push(it);
    }
    return groups;
}

// Local midnight for a Y-m-d string (avoids the UTC day-shift of new Date('2026-08-03')).
function parseDay(ymd: string) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}

// A short due badge relative to today: Overdue / Today / weekday.
function dueBadge(ymd: string, t: TFn, locale: string): { label: string; tone: string } {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const due = parseDay(ymd);
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    if (days < 0) return { label: t('dashboard.overdue'), tone: 'bg-destructive/10 text-destructive' };
    if (days === 0) return { label: t('dashboard.today'), tone: 'bg-accent-subtle text-primary' };
    return { label: due.toLocaleDateString(locale, { weekday: 'short' }), tone: 'bg-surface text-muted-foreground' };
}

// Time-of-day greeting key, resolved on the client so it matches the viewer's clock.
function greetingKey() {
    const h = new Date().getHours();
    if (h < 12) return 'dashboard.good_morning';
    if (h < 18) return 'dashboard.good_afternoon';
    return 'dashboard.good_evening';
}

// Side-rail shortcut into a family area. Solid-green icon well, DS card treatment.
function Shortcut({ href, icon: Icon, title, sub }: { href: string; icon: typeof Wallet; title: string; sub: string }) {
    return (
        <Link href={href} prefetch className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 transition-colors">
            <span className="bg-tile text-tile-foreground flex size-9 items-center justify-center rounded-lg">
                <Icon className="size-4.5" />
            </span>
            <span className="min-w-0">
                <span className="text-primary block truncate font-medium">{title}</span>
                <span className="text-muted-foreground block truncate text-sm">{sub}</span>
            </span>
        </Link>
    );
}

// One dated row inside Today / This Week: it IS the task / shopping item, so it renders as a real
// check-off row. Tapping the circle marks it done/bought (it drops off on the next dashboard load);
// the rest of the row opens the list it lives in. Due badge on the right.
function AgendaRow({ item }: { item: AgendaItem }) {
    const t = useT();
    const locale = useLocale();
    const badge = dueBadge(item.dueDate, t, locale);
    const [done, setDone] = useState(false);
    const toggle = () => {
        if (!item.toggleUrl || done) return; // one-way: a second tap before it drops off does nothing
        setDone(true); // optimistic check; the reload after the save removes the row
        // lib/save: never cancelled by the next tap; if it didn't save, the circle unticks again.
        void save('patch', item.toggleUrl, item.toggleBody ?? { done: true }).then((result) => result !== 'ok' && setDone(false));
    };
    return (
        <div className="bg-surface-card flex items-center gap-2.5 rounded-xl border px-3 py-2.5">
            {item.toggleUrl ? (
                <button
                    type="button"
                    onClick={toggle}
                    aria-label={done ? t('dashboard.is_done') : t('dashboard.mark_done')}
                    className={`flex size-6 shrink-0 items-center justify-center rounded-full border transition-colors ${
                        done ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border'
                    }`}
                >
                    {done && <Check className="size-3.5" />}
                </button>
            ) : (
                // Display-only row (e.g. a calendar event): a dot instead of a tick, nothing to toggle.
                <span className="bg-accent-strong mx-2 size-2 shrink-0 rounded-full" aria-hidden />
            )}
            <Link href={item.href} prefetch className="flex min-w-0 flex-1 items-center gap-2.5">
                <span className="min-w-0 flex-1">
                    <span className={`block truncate text-[15px] ${done ? 'text-muted-foreground line-through' : 'text-primary'}`}>{item.title}</span>
                    <span className="text-muted-foreground block truncate text-sm">{item.subtitle}</span>
                </span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${badge.tone}`}>{badge.label}</span>
            </Link>
        </div>
    );
}

export default function Dashboard({
    recent = [],
    today = [],
    thisWeek = [],
    widgets = {},
}: {
    recent?: DashItem[];
    today?: AgendaItem[];
    thisWeek?: AgendaItem[];
    // Per-module headline data (DashboardWidgets): only present when that module is enabled.
    widgets?: {
        spending?: { total: number; currency: string };
        birthdays?: { personId: number; name: string; turning: number | null }[];
        calendar?: {
            id: number;
            title: string;
            icon: string;
            color: string | null;
            href: string;
            start: string;
            end: string;
            now: boolean;
            dayOf: number | null;
            days: number;
            inDays: number | null;
        }[];
    };
}) {
    const { auth, modules } = usePage<SharedData & { modules?: { enabled?: string[] } }>().props;
    const t = useT();
    const locale = useLocale();
    const firstName = auth.user.name.split(' ')[0];
    const spendingOn = (modules?.enabled ?? []).includes('spending');
    const todayLabel = new Date().toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' });
    // Month name in the viewer's locale for the Spending card ("September" / "Septembrie").
    const monthName = new Date().toLocaleDateString(locale, { month: 'long' });

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.dashboard'), href: '/dashboard' }]}>
            <Head title={t('nav.dashboard')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-7 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-primary text-3xl">
                            {t(greetingKey())}, {firstName}
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">{todayLabel}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href="/family/members"
                            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                        >
                            <Users className="size-4" />
                            {t('nav.members')}
                        </Link>
                        <Link
                            href="/settings"
                            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                        >
                            <Settings className="size-4" />
                            {t('nav.settings')}
                        </Link>
                    </div>
                </div>

                {/* Spending headline: this month's total (same figure + currency as the Spending overview
                    widget). The card opens Spending on its default Expenses tab; the "+" opens it with the
                    ?new=1 create-intent so the add-expense form is already open on arrival. Two sibling
                    links (not a button inside a link) so both stay valid, focusable targets. */}
                {widgets.spending && (
                    <div className="bg-surface-card hover:border-accent flex items-center rounded-xl border transition-colors">
                        <Link href="/m/spending" prefetch className="flex min-w-0 flex-1 items-center gap-3 p-4">
                            <span className="bg-tile text-tile-foreground flex size-10 shrink-0 items-center justify-center rounded-lg">
                                <Wallet className="size-5" />
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="font-heading text-primary text-lg capitalize">{monthName}</span>
                                <span className="text-primary font-mono text-2xl font-semibold tabular-nums">
                                    {money(widgets.spending.total, widgets.spending.currency)}
                                </span>
                                <span className="text-muted-foreground text-xs">{t('spending.total_spent')}</span>
                            </span>
                        </Link>
                        <Link
                            href="/m/spending?new=1"
                            aria-label={t('spending.add_expense')}
                            className="bg-accent-strong text-accent-strong-foreground mr-4 flex size-11 shrink-0 items-center justify-center rounded-full transition-opacity hover:opacity-90"
                        >
                            <Plus className="size-5" />
                        </Link>
                    </div>
                )}

                {/* Now & next: multi-day events happening now or starting within 30 days (calendar module). */}
                {widgets.calendar && (
                    <div className="bg-surface-card flex flex-col gap-1 rounded-xl border p-4">
                        <span className="text-muted-foreground text-xs">{t('calendar.now_next')}</span>
                        {widgets.calendar.map((e) => (
                            <Link key={`${e.id}-${e.href}-${e.start}`} href={e.href} prefetch className="flex items-center gap-3 py-1.5">
                                <span className="bg-tile text-tile-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <DynamicLucideIcon name={e.icon} className="size-4" />
                                </span>
                                <span className="text-primary min-w-0 flex-1 truncate">{e.title}</span>
                                <span
                                    className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${e.now ? 'bg-accent-subtle text-primary' : 'bg-surface text-muted-foreground'}`}
                                >
                                    {e.now
                                        ? t('calendar.day_of', { n: e.dayOf ?? 1, total: e.days })
                                        : t('calendar.starts_in', { count: e.inDays ?? 0 })}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}

                {/* Birthday today: one card per person celebrating, plus a single confetti fall on the first
                    open of the day (keyed by date + who, so a second birthday still gets its moment). */}
                {widgets.birthdays?.map((b) => (
                    <Link
                        key={b.personId}
                        href="/m/birthdays"
                        prefetch
                        className="bg-surface-card hover:border-accent flex items-center gap-4 rounded-xl border p-4 transition-colors"
                    >
                        <span className="bg-tile flex size-14 shrink-0 items-center justify-center rounded-xl text-3xl" aria-hidden="true">
                            🎂
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="font-heading text-primary text-xl leading-tight">
                                {b.turning !== null
                                    ? t('birthdays.today_turns', { name: b.name, age: b.turning })
                                    : t('birthdays.today_noage', { name: b.name })}
                            </span>
                            <span className="text-muted-foreground mt-1 text-sm">{t('birthdays.card_sub')}</span>
                        </span>
                    </Link>
                ))}
                {widgets.birthdays && widgets.birthdays.length > 0 && (
                    <Confetti onceKey={`${new Date().toISOString().slice(0, 10)}:${widgets.birthdays.map((b) => b.personId).join(',')}`} />
                )}

                {today.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.today')}</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {today.map((it) => (
                                <AgendaRow key={agendaKey(it)} item={it} />
                            ))}
                        </div>
                    </section>
                )}

                {thisWeek.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.this_week')}</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {thisWeek.map((it) => (
                                <AgendaRow key={agendaKey(it)} item={it} />
                            ))}
                        </div>
                    </section>
                )}

                {recent.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.pick_up')}</h2>
                        {/* Group the recently-opened entities by category, preserving recency order
                            (the category of the most-recent open comes first). */}
                        {groupRecent(recent).map((group) => (
                            <div key={group.category} className="flex flex-col gap-2">
                                <h3 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">{t(group.category)}</h3>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {group.items.map((it) => {
                                        return (
                                            <Link
                                                key={it.href}
                                                href={it.href}
                                                prefetch
                                                className="bg-surface-card hover:border-accent flex items-start gap-3 rounded-xl border p-3 transition-colors"
                                            >
                                                <ItemTile it={it} />
                                                {/* min-w-0 + wrapping so a long title/subtitle wraps to a couple of lines
                                                    instead of forcing the card super wide. */}
                                                <span className="min-w-0 flex-1">
                                                    <span className="text-primary line-clamp-2 font-medium break-words">{it.title}</span>
                                                    <span className="text-muted-foreground line-clamp-2 text-sm break-words">{it.subtitle}</span>
                                                </span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </section>
                )}

                {/* Home-screen widgets contributed by modules (e.g. Birthdays → Upcoming birthdays).
                    Renders nothing when no enabled module registers a dashboard.widgets component. */}
                <Slot name="dashboard.widgets" />

                <section className="flex flex-col gap-3">
                    <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.jump_back_in')}</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Shortcut href="/family/members" icon={Users} title={t('nav.members')} sub={t('dashboard.members_sub')} />
                        <Shortcut href="/family/modules" icon={Blocks} title={t('nav.modules')} sub={t('dashboard.modules_sub')} />
                        {spendingOn && <Shortcut href="/m/spending" icon={Wallet} title={t('nav.spending')} sub={t('dashboard.spending_sub')} />}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
