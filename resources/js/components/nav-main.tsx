import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    return (
        <SidebarGroup className="px-2 py-0">
            {/* max-md: = the phone drawer only (below md the desktop sidebar is hidden and this renders in a
                Sheet). Touch-sized there: 17px text, 22px icons, 48px rows; the desktop sidebar is unchanged. */}
            <SidebarGroupLabel className="max-md:h-9 max-md:text-sm">Family</SidebarGroupLabel>
            <SidebarMenu className="max-md:gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={item.url === page.url}
                            className="max-md:h-12 max-md:gap-3 max-md:px-3 max-md:text-[17px] max-md:[&>svg]:size-[22px]"
                        >
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
