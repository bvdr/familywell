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
            {/* Installed phone app: <main> is the scroller, the document never scrolls. After a resume iOS WebKit
                can keep a keyboard-short visual viewport with no keyboard on screen, and while the DOCUMENT
                scrolls it then slides every position:fixed element (tab bar, sheets) up to a keyboard height off
                the bottom (2026-10-20 recording). With no document scroll there is nothing to slide; this is the
                fix reported for the same symptom (club-o-clock #42). translateZ(0) on the bar (PR #147) didn't
                help because it doesn't change what the bar is fixed to. scroll-region keeps Inertia's scroll
                restore (back button, preserveScroll) working on <main>. Scoped to standalone + touch so Safari
                tabs keep their collapsing toolbar and CI/desktop keep document scroll; not max-md, so the
                landscape app-switcher snapshot (wider than md) doesn't tear the scroller down and lose position.
                No overscroll-contain: at <main>'s end a drag must still chain to the root, which is how a
                FormActions Save row is reached while the keyboard overlays the page. */}
            <AppContent
                variant="sidebar"
                scroll-region=""
                className="[@media(display-mode:standalone)_and_(pointer:coarse)]:h-dvh [@media(display-mode:standalone)_and_(pointer:coarse)]:overflow-y-auto"
            >
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
