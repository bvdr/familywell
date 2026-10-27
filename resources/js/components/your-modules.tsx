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

// The dashboard's "Your modules" grid (design JGdKu / g588Mw): one tile per enabled module with its live one-line
// status from DashboardSummaries ("6 to buy", "2 due today"). Two across on phones, four on tablet and up. The
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
        <section className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between px-1">
                <h2 className="font-heading text-primary text-lg">{pinned ? t('dashboard.pinned') : t('dashboard.your_modules')}</h2>
                {pinned ? (
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="text-muted-foreground inline-flex items-center gap-1 text-sm font-medium"
                    >
                        {t('dashboard.edit_pins')}
                    </button>
                ) : (
                    <Link href="/family/modules" className="text-muted-foreground inline-flex items-center gap-1 text-sm font-medium">
                        <Plus className="size-3.5" />
                        {t('dashboard.add_module')}
                    </Link>
                )}
            </div>
            <div data-tour="modules" className="grid grid-cols-2 gap-2.5 md:grid-cols-4">
                {ids.map((id, i) => {
                    const { icon: Icon, href } = MODULES[id];
                    const line = summaries[id];
                    return (
                        <Link
                            key={id}
                            href={href}
                            className="bg-surface-card hover:border-accent flex flex-col gap-3 rounded-2xl border p-4 transition-colors"
                        >
                            {/* The first tile's icon in the accent, like the design's Shopping tile. */}
                            <span
                                className={`flex size-9 items-center justify-center rounded-[10px] ${i === 0 ? 'bg-accent-strong text-accent-strong-foreground' : 'bg-surface text-primary'}`}
                            >
                                <Icon className="size-[18px]" />
                            </span>
                            <span className="min-w-0">
                                <span className="text-primary block text-[15px] font-bold">{t(`onboarding.module_${id}`)}</span>
                                {line && <span className="text-muted-foreground block truncate text-[12.5px]">{t(line.key, line.params)}</span>}
                            </span>
                        </Link>
                    );
                })}
            </div>
            <PinModulesModal open={editing} onOpenChange={setEditing} />
        </section>
    );
}
