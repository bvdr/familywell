import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Blocks,
    Cake,
    CalendarDays,
    Car,
    ChefHat,
    ChevronDown,
    Contact,
    FolderKanban,
    Import,
    LayoutGrid,
    LayoutList,
    ListChecks,
    type LucideIcon,
    Package,
    ShoppingCart,
    StickyNote,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogo from './app-logo';

// titleKey is a translation key, resolved per-render so the sidebar is localized.
const baseNavItems: { titleKey: string; url: string; icon: LucideIcon }[] = [
    { titleKey: 'nav.dashboard', url: '/dashboard', icon: LayoutGrid },
    { titleKey: 'nav.members', url: '/family/members', icon: Users },
    { titleKey: 'nav.modules', url: '/family/modules', icon: Blocks },
];

// Page-modules that earn a sidebar link when the family has enabled them.
const moduleNavItems: Record<string, { titleKey: string; url: string; icon: LucideIcon }> = {
    spending: { titleKey: 'nav.spending', url: '/m/spending', icon: Wallet },
    shopping: { titleKey: 'nav.shopping', url: '/m/shopping', icon: ShoppingCart },
    tasks: { titleKey: 'nav.tasks', url: '/m/tasks', icon: ListChecks },
    projects: { titleKey: 'nav.projects', url: '/m/projects', icon: FolderKanban },
    calendar: { titleKey: 'nav.calendar', url: '/m/calendar', icon: CalendarDays },
    notes: { titleKey: 'nav.notes', url: '/m/notes', icon: StickyNote },
    recipes: { titleKey: 'nav.recipes', url: '/m/recipes', icon: ChefHat },
    contacts: { titleKey: 'nav.contacts', url: '/m/contacts', icon: Contact },
    birthdays: { titleKey: 'nav.birthdays', url: '/m/birthdays', icon: Cake },
    inventory: { titleKey: 'nav.inventory', url: '/m/inventory', icon: Package },
    vehicles: { titleKey: 'nav.vehicles', url: '/m/vehicles', icon: Car },
    expense_import: { titleKey: 'nav.expense_import', url: '/m/expense-import', icon: Import },
};

// Fades the bottom of the scrollable nav while there is more below, and drops the fade at the end. On a short
// phone the last link (e.g. Vehicles) can sit exactly under the pinned user row with no hint the list scrolls.
// The fade is 64px (not a thin 40px): when a hidden row starts right at the edge, only a fade that reaches into
// the last VISIBLE link (dimming it) actually reads as "more below"; a short one just fades empty padding.
// A callback ref (state), not useRef: on a phone the list lives in a Sheet that mounts only while it is open.
function useMoreBelow() {
    const [el, setEl] = useState<HTMLDivElement | null>(null);
    const [more, setMore] = useState(false);
    useEffect(() => {
        if (!el) return;
        const update = () => setMore(el.scrollHeight - el.scrollTop - el.clientHeight > 1);
        const ro = new ResizeObserver(update); // the drawer opening, rotation, modules toggled on/off
        ro.observe(el);
        el.addEventListener('scroll', update, { passive: true });
        update();
        return () => {
            ro.disconnect();
            el.removeEventListener('scroll', update);
        };
    }, [el]);
    return [setEl, more] as const;
}

export function AppSidebar() {
    const [navRef, moreBelow] = useMoreBelow();
    const t = useT();
    const props = usePage().props as { modules?: { enabled?: string[] }; pins?: string[] | null };
    const enabled = props.modules?.enabled ?? [];
    const toItem = (i: { titleKey: string; url: string; icon: LucideIcon }): NavItem => ({ title: t(i.titleKey), url: i.url, icon: i.icon });
    const mainNavItems: NavItem[] = [...baseNavItems, ...enabled.map((id) => moduleNavItems[id]).filter(Boolean)].map(toItem);

    // "Pin your top 4": someone who pinned modules (the onboarding, or Edit on the dashboard) gets Home, their pins on
    // top, and everything else folded under "All modules +N". Someone who never pinned keeps the flat list as before.
    const pinned = (props.pins ?? []).filter((id) => moduleNavItems[id] && enabled.includes(id));
    const rest = enabled.filter((id) => moduleNavItems[id] && !pinned.includes(id));
    const [allOpen, setAllOpen] = useState(false);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* A mask (not an overlay) so the links themselves fade out, on any skin's background. */}
            <SidebarContent
                ref={navRef}
                // What the onboarding tour spotlights on a computer (design D07): where their modules live.
                data-tour={pinned.length ? undefined : 'modules-sidebar'}
                data-more={moreBelow || undefined}
                className="data-[more]:[mask-image:linear-gradient(to_bottom,black_calc(100%-64px),transparent)]"
            >
                {pinned.length ? (
                    <>
                        <NavMain items={[toItem(baseNavItems[0])]} />
                        {/* The tour's first stop (design D07) spotlights the pins and the way to the rest. */}
                        <div data-tour="modules-sidebar">
                            <NavMain label={t('nav.pinned')} items={pinned.map((id) => toItem(moduleNavItems[id]))} />
                            {rest.length > 0 && (
                                <Collapsible open={allOpen} onOpenChange={setAllOpen}>
                                    <SidebarGroup className="px-2 py-0">
                                        <SidebarMenu>
                                            <SidebarMenuItem>
                                                <CollapsibleTrigger asChild>
                                                    <SidebarMenuButton className="max-md:h-12 max-md:gap-3 max-md:px-3 max-md:text-[17px] md:h-10 md:gap-2.5 md:text-[15px] max-md:[&>svg]:size-[22px] md:[&>svg]:size-5">
                                                        <LayoutList />
                                                        <span className="flex-1 text-left">{t('nav.all_modules')}</span>
                                                        {/* The count is of the modules people pick between (the onboarding's "11"); the Expense import add-on is listed but not counted. */}
                                                        <span className="text-muted-foreground text-xs">
                                                            +{rest.filter((id) => id !== 'expense_import').length}
                                                        </span>
                                                        <ChevronDown className={`size-4! transition-transform ${allOpen ? 'rotate-180' : ''}`} />
                                                    </SidebarMenuButton>
                                                </CollapsibleTrigger>
                                            </SidebarMenuItem>
                                        </SidebarMenu>
                                        <CollapsibleContent>
                                            <NavMain label={null} items={rest.map((id) => toItem(moduleNavItems[id]))} />
                                        </CollapsibleContent>
                                    </SidebarGroup>
                                </Collapsible>
                            )}
                        </div>
                        <NavMain label={null} items={baseNavItems.slice(1).map(toItem)} />
                    </>
                ) : (
                    <NavMain items={mainNavItems} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
