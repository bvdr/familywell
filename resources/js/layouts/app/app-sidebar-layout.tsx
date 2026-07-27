import { AddToHomeScreenHint } from '@/components/add-to-home-screen-hint';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    actions,
}: {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    actions?: React.ReactNode;
}) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} actions={actions} />
                {/* Clear the floating tab bar: its pill height plus the same safe-area inset the bar
                    itself uses, so the last row never hides under it on notched phones. */}
                <div className="pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-0">{children}</div>
            </AppContent>
            <MobileTabBar />
            <AddToHomeScreenHint />
        </AppShell>
    );
}
