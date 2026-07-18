import { AddToHomeScreenHint } from '@/components/add-to-home-screen-hint';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* pb keeps content clear of the floating mobile tab bar (~56px + safe area). */}
                <div className="pb-24 md:pb-0">{children}</div>
            </AppContent>
            <MobileTabBar />
            <AddToHomeScreenHint />
        </AppShell>
    );
}
