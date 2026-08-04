import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Blocks, FolderKanban, LayoutGrid, ListChecks, ShoppingCart, StickyNote, Users, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Members',
        url: '/family/members',
        icon: Users,
    },
    {
        title: 'Modules',
        url: '/family/modules',
        icon: Blocks,
    },
];

// Page-modules that earn a sidebar link when the family has enabled them.
const moduleNavItems: Record<string, NavItem> = {
    spending: { title: 'Spending', url: '/m/spending', icon: Wallet },
    shopping: { title: 'Shopping', url: '/m/shopping', icon: ShoppingCart },
    tasks: { title: 'Tasks', url: '/m/tasks', icon: ListChecks },
    projects: { title: 'Projects', url: '/m/projects', icon: FolderKanban },
    notes: { title: 'Notes', url: '/m/notes', icon: StickyNote },
};

export function AppSidebar() {
    const enabled = (usePage().props as { modules?: { enabled?: string[] } }).modules?.enabled ?? [];
    const mainNavItems = [...baseNavItems, ...enabled.map((id) => moduleNavItems[id]).filter(Boolean)];

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
