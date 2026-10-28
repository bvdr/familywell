import { Link } from '@inertiajs/react';
import {
    Cake,
    CalendarDays,
    Car,
    ChefHat,
    Contact,
    FolderKanban,
    ListChecks,
    NotebookPen,
    Package,
    Plus,
    ShoppingCart,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

import { PinModulesModal, usePinned } from '@/components/pin-modules';
import { useT } from '@/lib/i18n';

// The dashboard's "Your modules" grid (design XMkKm, Home H1): one tile per enabled module with its live one-line
// status from DashboardSummaries ("6 to buy", "2 due today"). Two across: on desktop it sits in Home's narrow right column. The
// onboarding tour spotlights it (data-tour="modules"); "+ Add" goes to the modules settings.
export const MODULES: Record<string, { icon: LucideIcon; href: string }> = {
    shopping: { icon: ShoppingCart, href: '/m/shopping' },
    tasks: { icon: ListChecks, href: '/m/tasks' },
    calendar: { icon: CalendarDays, href: '/m/calendar' },
    spending: { icon: Wallet, href: '/m/spending' },
    notes: { icon: NotebookPen, href: '/m/notes' },
    recipes: { icon: ChefHat, href: '/m/recipes' },
    inventory: { icon: Package, href: '/m/inventory' },
    vehicles: { icon: Car, href: '/m/vehicles' },
    projects: { icon: FolderKanban, href: '/m/projects' },
    contacts: { icon: Contact, href: '/m/contacts' },
    birthdays: { icon: Cake, href: '/m/birthdays' },
};

export type ModuleSummary = { key: string; params?: Record<string, string | number> };

export function YourModules({ enabled, summaries }: { enabled: string[]; summaries: Record<string, ModuleSummary> }) {
    const t = useT();
    // "Pin your top 4": once someone has pinned modules the grid is just those (the rest are under More / All modules);
    // before that it is every module that's on, as it always was.
    const pinned = usePinned();
    const [editing, setEditing] = useState(false);
    const ids = pinned ?? Object.keys(MODULES).filter((id) => enabled.includes(id));
    if (ids.length === 0) return null;

    return (
        <section className="flex flex-col gap-2.5">
            {/* Same mono label row as the rest of Home ("YOUR MODULES 8 ...... Edit"). */}
            <div className="flex items-baseline justify-between px-1">
                <h2 className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.14em] uppercase">
                    {pinned ? t('dashboard.pinned') : t('dashboard.your_modules')}
                    <span className="text-primary ml-2">{ids.length}</span>
                </h2>
                {pinned ? (
                    <button type="button" onClick={() => setEditing(true)} className="text-primary text-sm font-medium">
                        {t('dashboard.edit_pins')}
                    </button>
                ) : (
                    <Link href="/family/modules" className="text-primary inline-flex items-center gap-1 text-sm font-medium">
                        <Plus className="size-3.5" />
                        {t('dashboard.add_module')}
                    </Link>
                )}
            </div>
            <div data-tour="modules" className="grid grid-cols-2 gap-2.5">
                {ids.map((id) => {
                    const { icon: Icon, href } = MODULES[id];
                    const line = summaries[id];
                    return (
                        <Link
                            key={id}
                            href={href}
                            className="bg-surface-card hover:border-primary/40 flex min-h-32 flex-col justify-between gap-4 rounded-2xl border p-4 transition-colors"
                        >
                            <span className="bg-surface-card text-primary flex size-10 items-center justify-center rounded-xl border">
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="font-heading text-primary block text-lg leading-tight font-bold">
                                    {t(`onboarding.module_${id}`)}
                                </span>
                                {line && <span className="text-muted-foreground block truncate text-sm">{t(line.key, line.params)}</span>}
                            </span>
                        </Link>
                    );
                })}
            </div>
            <PinModulesModal open={editing} onOpenChange={setEditing} />
        </section>
    );
}
