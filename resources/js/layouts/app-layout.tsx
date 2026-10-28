import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    // Right-aligned header controls, rendered next to the breadcrumbs. Any entity page can pass this.
    actions?: React.ReactNode;
    // Replaces the breadcrumb header on phones (Home's own top bar: family, search, alerts, avatar).
    mobileHeader?: React.ReactNode;
}

export default ({ children, breadcrumbs, actions, mobileHeader, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} actions={actions} mobileHeader={mobileHeader} {...props}>
        {children}
    </AppLayoutTemplate>
);
