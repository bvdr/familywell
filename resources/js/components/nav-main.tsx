import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

// `label` defaults to the family group's; `null` drops the heading (a list that continues the group above it).
export function NavMain({ items = [], label }: { items: NavItem[]; label?: string | null }) {
    const page = usePage();
    const t = useT();
    return (
        <SidebarGroup className="px-2 py-0">
            {/* max-md: = the phone drawer (below md the desktop sidebar is hidden and this renders in a Sheet):
                touch-sized, 17px text, 22px icons, 48px rows. md: = the desktop sidebar: a step up from the stock
                compact shadcn size (14px/16px/32px) to 15px text, 20px icons, 40px rows. The collapsed icon rail
                keeps its 32px buttons, so its icons stay 16px (group-data-[collapsible=icon]). */}
            {label !== null && (
                <SidebarGroupLabel className="max-md:h-9 max-md:text-sm md:text-[13px]">{label ?? t('family.nav_label')}</SidebarGroupLabel>
            )}
            <SidebarMenu className="max-md:gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={item.url === page.url}
                            className="max-md:h-12 max-md:gap-3 max-md:px-3 max-md:text-[17px] md:h-10 md:gap-2.5 md:text-[15px] max-md:[&>svg]:size-[22px] md:[&>svg]:size-5 md:group-data-[collapsible=icon]:[&>svg]:size-4"
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
