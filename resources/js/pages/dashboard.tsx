import AppLayout from '@/layouts/app-layout';
import { Slot } from '@/lib/modules';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Blocks, FolderKanban, ListChecks, type LucideIcon, Settings, ShoppingCart, Users, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

// A recently-opened entity ("Pick up where you left off"). visitedAt drives the order server-side.
type DashItem = { title: string; subtitle: string; href: string; icon: string };
// A dated leaf item (a task / shopping item due on a day) for the Today / This Week sections.
type AgendaItem = DashItem & { dueDate: string };

// Map a provider's icon key to a lucide icon; fall back to a neutral one.
const ICONS: Record<string, LucideIcon> = { wallet: Wallet, 'shopping-cart': ShoppingCart, 'folder-kanban': FolderKanban, 'list-checks': ListChecks };
const iconFor = (key: string) => ICONS[key] ?? Blocks;

// Local midnight for a Y-m-d string (avoids the UTC day-shift of new Date('2026-08-03')).
function parseDay(ymd: string) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}

// A short due badge relative to today: Overdue / Today / weekday.
function dueBadge(ymd: string): { label: string; tone: string } {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const due = parseDay(ymd);
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    if (days < 0) return { label: 'Overdue', tone: 'bg-destructive/10 text-destructive' };
    if (days === 0) return { label: 'Today', tone: 'bg-accent-subtle text-primary' };
    return { label: due.toLocaleDateString(undefined, { weekday: 'short' }), tone: 'bg-surface text-muted-foreground' };
}

// Time-of-day greeting, resolved on the client so it matches the viewer's clock.
function greeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
}

const todayLabel = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });

// Side-rail shortcut into a family area. Solid-green icon well, DS card treatment.
function Shortcut({ href, icon: Icon, title, sub }: { href: string; icon: typeof Wallet; title: string; sub: string }) {
    return (
        <Link href={href} prefetch className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 transition-colors">
            <span className="bg-primary text-primary-foreground flex size-9 items-center justify-center rounded-lg">
                <Icon className="size-4.5" />
            </span>
            <span className="min-w-0">
                <span className="text-primary block truncate font-medium">{title}</span>
                <span className="text-muted-foreground block truncate text-sm">{sub}</span>
            </span>
        </Link>
    );
}

// One dated row inside Today / This Week: icon, title, where it lives, and a due badge.
function AgendaRow({ item }: { item: AgendaItem }) {
    const Icon = iconFor(item.icon);
    const badge = dueBadge(item.dueDate);
    return (
        <Link
            href={item.href}
            prefetch
            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 transition-colors"
        >
            <span className="bg-primary text-primary-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                <Icon className="size-4.5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="text-primary block truncate font-medium">{item.title}</span>
                <span className="text-muted-foreground block truncate text-sm">{item.subtitle}</span>
            </span>
            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${badge.tone}`}>{badge.label}</span>
        </Link>
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
    const firstName = auth.user.name.split(' ')[0];
    const spendingOn = (modules?.enabled ?? []).includes('spending');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-7 p-4 md:p-8">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="font-heading text-primary text-3xl">
                            {greeting()}, {firstName}
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">{todayLabel}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href="/family/members"
                            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                        >
                            <Users className="size-4" />
                            Members
                        </Link>
                        <Link
                            href="/settings/profile"
                            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors"
                        >
                            <Settings className="size-4" />
                            Settings
                        </Link>
                    </div>
                </div>

                {today.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">Today</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {today.map((it) => (
                                <AgendaRow key={it.href + it.title} item={it} />
                            ))}
                        </div>
                    </section>
                )}

                {thisWeek.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">This Week</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {thisWeek.map((it) => (
                                <AgendaRow key={it.href + it.title} item={it} />
                            ))}
                        </div>
                    </section>
                )}

                {recent.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">Pick up where you left off</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {recent.map((it) => {
                                const Icon = iconFor(it.icon);
                                return (
                                    <Link
                                        key={it.href}
                                        href={it.href}
                                        prefetch
                                        className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 transition-colors"
                                    >
                                        <span className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-lg">
                                            <Icon className="size-5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="text-primary block truncate font-medium">{it.title}</span>
                                            <span className="text-muted-foreground block truncate font-mono text-sm tabular-nums">{it.subtitle}</span>
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    </section>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <div className="grid auto-rows-min gap-4 sm:grid-cols-2">
                        <Slot
                            name="dashboard.widgets"
                            fallback={
                                <Link
                                    href="/family/modules"
                                    className="bg-surface-card hover:border-accent flex min-h-56 flex-col items-center justify-center gap-3 rounded-2xl border border-dashed p-8 text-center transition-colors sm:col-span-2"
                                >
                                    <span className="bg-accent-subtle text-primary flex size-12 items-center justify-center rounded-2xl">
                                        <Blocks className="size-6" />
                                    </span>
                                    <span className="font-heading text-primary text-xl">A calm start</span>
                                    <span className="text-muted-foreground max-w-xs text-sm">
                                        Turn on a module like Tasks or Shopping and its widgets will gather here.
                                    </span>
                                </Link>
                            }
                        />
                    </div>

                    <aside className="flex flex-col gap-3">
                        <h2 className="font-heading text-primary px-1 text-lg">Jump back in</h2>
                        <Shortcut href="/family/members" icon={Users} title="Members" sub="Who's in your family" />
                        <Shortcut href="/family/modules" icon={Blocks} title="Modules" sub="Turn features on or off" />
                        {spendingOn && <Shortcut href="/m/spending" icon={Wallet} title="Spending" sub="Shared expense trackers" />}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
