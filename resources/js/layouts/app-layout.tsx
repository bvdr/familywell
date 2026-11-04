import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    // Right-aligned header controls, rendered next to the breadcrumbs. Any entity page can pass this.
    actions?: React.ReactNode;
    // Replaces the breadcrumb header on phones (Home's own top bar: family, search, alerts, avatar).
    mobileHeader?: React.ReactNode;
    // Hide the breadcrumb bar on phones (desktop keeps it): the settings pages have their own back pill.
    hideMobileHeader?: boolean;
    // Hide the phone tab bar: a page whose own bottom bar is where the thumb goes (a shopping list's composer, S2).
    hideTabBar?: boolean;
}

export default ({ children, breadcrumbs, actions, mobileHeader, hideMobileHeader, hideTabBar, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate
        breadcrumbs={breadcrumbs}
        actions={actions}
        mobileHeader={mobileHeader}
        hideMobileHeader={hideMobileHeader}
        hideTabBar={hideTabBar}
        {...props}
    >
        {children}
    </AppLayoutTemplate>
);
