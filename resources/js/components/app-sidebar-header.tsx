import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationsBell } from '@/components/notifications-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    actions,
    mobileHeader,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    // Right-aligned per-page controls (share, guest link, ...). Global slot so any entity can use it.
    actions?: React.ReactNode;
    // A page's own phone header (Home's top bar). Shown instead of the breadcrumb row below md; desktop is unchanged.
    mobileHeader?: React.ReactNode;
}) {
    return (
        <>
            {mobileHeader && <header className="flex shrink-0 items-center px-4 pt-3 pb-1 md:hidden">{mobileHeader}</header>}
            <header
                className={`border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4 ${mobileHeader ? 'max-md:hidden' : ''}`}
            >
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <SidebarTrigger className="-ml-1 shrink-0" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
                {/* Global notifications bell + any per-page actions, pinned to the right. */}
                <div className="flex shrink-0 items-center gap-2 pl-2">
                    {actions}
                    <NotificationsBell />
                </div>
            </header>
        </>
    );
}
