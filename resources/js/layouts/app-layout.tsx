import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    // Right-aligned header controls, rendered next to the breadcrumbs. Any entity page can pass this.
    actions?: React.ReactNode;
}

export default ({ children, breadcrumbs, actions, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} actions={actions} {...props}>
        {children}
    </AppLayoutTemplate>
);
