import { ChevronLeft } from 'lucide-react';

import { Breadcrumbs } from '@/components/breadcrumbs';
import { HomeTopBar } from '@/components/home-top-bar';
import { NotificationsBell } from '@/components/notifications-bell';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useT } from '@/lib/i18n';
import { useNavMode } from '@/lib/nav-origin';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    actions,
    mobileHeader,
    hideMobileHeader,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    // Right-aligned per-page controls (share, guest link, ...). Global slot so any entity can use it.
    actions?: React.ReactNode;
    // A page's own phone header (Home's top bar, a detail page's back pill). Shown instead of the bars below on phones;
    // desktop is unchanged.
    mobileHeader?: React.ReactNode;
    // No top bar at all on phones: pages that bring their own way back (the settings pages' back pill).
    hideMobileHeader?: boolean;
}) {
    const t = useT();
    // Phones: a module opened from the tab bar or More gets the family top bar; a page reached from inside the app gets
    // a back button that retraces your steps (lib/nav-origin). A page with its own actions (share, ...) keeps the
    // title row on phones so those stay reachable, even when opened from the top.
    const mode = useNavMode();
    const familyBar = !mobileHeader && !hideMobileHeader && mode === 'root' && !actions;
    const phoneHeader = mobileHeader ?? (familyBar ? <HomeTopBar /> : null);
    return (
        <>
            {phoneHeader && <header className="flex shrink-0 items-center px-4 pt-3 pb-1 md:hidden">{phoneHeader}</header>}
            <header
                className={`border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4 ${phoneHeader || hideMobileHeader ? 'max-md:hidden' : ''}`}
            >
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    {mode === 'child' && (
                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            aria-label={t('common.back')}
                            className="text-primary hover:bg-surface -ml-2 flex size-9 shrink-0 items-center justify-center rounded-full md:hidden"
                        >
                            <ChevronLeft className="size-5.5" />
                        </button>
                    )}
                    <SidebarTrigger className={`-ml-1 shrink-0 ${mode === 'child' ? 'max-md:hidden' : ''}`} />
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
