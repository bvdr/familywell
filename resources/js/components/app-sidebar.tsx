import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Blocks,
    Cake,
    Car,
    ChefHat,
    Contact,
    FolderKanban,
    LayoutGrid,
    ListChecks,
    type LucideIcon,
    Package,
    ShoppingCart,
    StickyNote,
    Users,
    Wallet,
} from 'lucide-react';
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
    notes: { titleKey: 'nav.notes', url: '/m/notes', icon: StickyNote },
    recipes: { titleKey: 'nav.recipes', url: '/m/recipes', icon: ChefHat },
    contacts: { titleKey: 'nav.contacts', url: '/m/contacts', icon: Contact },
    birthdays: { titleKey: 'nav.birthdays', url: '/m/birthdays', icon: Cake },
    inventory: { titleKey: 'nav.inventory', url: '/m/inventory', icon: Package },
    vehicles: { titleKey: 'nav.vehicles', url: '/m/vehicles', icon: Car },
};

export function AppSidebar() {
    const t = useT();
    const enabled = (usePage().props as { modules?: { enabled?: string[] } }).modules?.enabled ?? [];
    const mainNavItems: NavItem[] = [...baseNavItems, ...enabled.map((id) => moduleNavItems[id]).filter(Boolean)].map((i) => ({
        title: t(i.titleKey),
        url: i.url,
        icon: i.icon,
    }));

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

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
