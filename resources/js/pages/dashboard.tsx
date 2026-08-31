import AppLayout from '@/layouts/app-layout';
import { useLocale, useT, type TFn } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Blocks, Check, FolderKanban, ListChecks, Settings, ShoppingCart, StickyNote, Users, Wallet, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

// A recently-opened entity ("Pick up where you left off"). visitedAt drives the order server-side.
type DashItem = { title: string; subtitle: string; href: string; icon: string };
// A dated leaf item (a task / shopping item due on a day) for the Today / This Week sections.
// toggleUrl: empty-body PATCH marks it done/bought (and it drops off on the next dashboard load).
type AgendaItem = DashItem & { dueDate: string; toggleUrl: string };

// Map a provider's icon key to a lucide icon; fall back to a neutral one.
const ICONS: Record<string, LucideIcon> = {
    wallet: Wallet,
    'shopping-cart': ShoppingCart,
    'folder-kanban': FolderKanban,
    'list-checks': ListChecks,
    'sticky-note': StickyNote,
};
const iconFor = (key: string) => ICONS[key] ?? Blocks;

// The recent items are grouped by category on the dashboard; the icon key doubles as the category
// (each module tags its items with a distinct icon).
// icon key -> nav translation key for the recent-items group headers.
const CATEGORY: Record<string, string> = {
    'list-checks': 'nav.tasks',
    'shopping-cart': 'nav.shopping',
    wallet: 'nav.spending',
    'folder-kanban': 'nav.projects',
    'sticky-note': 'nav.notes',
};
const categoryOf = (icon: string) => CATEGORY[icon] ?? 'Other';

// Group recent items by category, preserving the order the categories first appear. Since `recent`
// arrives most-recent-first, the category you opened most recently leads.
function groupRecent(recent: DashItem[]): { category: string; items: DashItem[] }[] {
    const groups: { category: string; items: DashItem[] }[] = [];
    const byCat = new Map<string, { category: string; items: DashItem[] }>();
    for (const it of recent) {
        const cat = categoryOf(it.icon);
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
        setDone(true); // optimistic check; the redirect back reloads the dashboard and removes it
        router.patch(item.toggleUrl, {}, { preserveScroll: true });
    };
    return (
        <div className="bg-surface-card flex items-center gap-2.5 rounded-xl border px-3 py-2.5">
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
}: {
    recent?: DashItem[];
    today?: AgendaItem[];
    thisWeek?: AgendaItem[];
}) {
    const { auth, modules } = usePage<SharedData & { modules?: { enabled?: string[] } }>().props;
    const t = useT();
    const locale = useLocale();
    const firstName = auth.user.name.split(' ')[0];
    const spendingOn = (modules?.enabled ?? []).includes('spending');
    const todayLabel = new Date().toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
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
                            href="/settings/profile"
                            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                        >
                            <Settings className="size-4" />
                            {t('nav.settings')}
                        </Link>
                    </div>
                </div>

                {today.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.today')}</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {today.map((it) => (
                                <AgendaRow key={it.href + it.title} item={it} />
                            ))}
                        </div>
                    </section>
                )}

                {thisWeek.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">{t('dashboard.this_week')}</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {thisWeek.map((it) => (
                                <AgendaRow key={it.href + it.title} item={it} />
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
                                        const Icon = iconFor(it.icon);
                                        return (
                                            <Link
                                                key={it.href}
                                                href={it.href}
                                                prefetch
                                                className="bg-surface-card hover:border-accent flex items-start gap-3 rounded-xl border p-3 transition-colors"
                                            >
                                                <span className="bg-tile text-tile-foreground mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-lg">
                                                    <Icon className="size-5" />
                                                </span>
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
