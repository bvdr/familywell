import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

// One nav row. max-md: = the phone drawer (below md the desktop sidebar is hidden and this renders in a Sheet):
// touch-sized, 17px text, 22px icons, 48px rows. md: = the desktop sidebar (design D10 "Desktop Sidebar", jTQcP):
// 40px rows, 18px icons, 14.5px medium text, 12px radius; the current page is a white card with a hairline and bold
// text (the inset ring, not a border, so the row doesn't grow by 2px). The collapsed icon rail keeps its 32px
// buttons, so its icons stay 16px (group-data-[collapsible=icon]). Exported for the "All modules" row.
export const NAV_ROW =
    'max-md:h-12 max-md:gap-3 max-md:px-3 max-md:text-[17px] max-md:[&>svg]:size-[22px] md:h-10 md:gap-3 md:rounded-xl md:px-2.5 md:text-[14.5px] md:font-medium md:[&>svg]:size-[18px] md:group-data-[collapsible=icon]:[&>svg]:size-4 md:data-[active=true]:font-bold md:data-[active=true]:shadow-[inset_0_0_0_1px_var(--color-sidebar-border)]';

// `label` defaults to the family group's; `null` drops the heading (a list that continues the group above it).
export function NavMain({ items = [], label }: { items: NavItem[]; label?: string | null }) {
    const page = usePage();
    const t = useT();
    return (
        <SidebarGroup className="px-2 py-0">
            {/* Desktop: the design's mono caps group label (PINNED, MODULES). */}
            {label !== null && (
                <SidebarGroupLabel className="max-md:h-9 max-md:text-sm md:mt-1.5 md:font-mono md:text-[11px] md:font-semibold md:tracking-[0.11em] md:uppercase">
                    {label ?? t('family.nav_label')}
                </SidebarGroupLabel>
            )}
            <SidebarMenu className="max-md:gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={item.url === page.url} className={NAV_ROW}>
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
