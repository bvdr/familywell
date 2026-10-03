import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

// key is a translation key resolved at render (so the nav is localized).
const sidebarNavItems: { key: string; url: string }[] = [
    { key: 'settings.profile', url: '/settings/profile' },
    { key: 'settings.password', url: '/settings/password' },
    { key: 'settings.appearance', url: '/settings/appearance' },
    { key: 'settings.language', url: '/settings/language' },
    { key: 'settings.notifications', url: '/settings/notifications' },
    { key: 'settings.media', url: '/settings/media' },
    { key: 'trash.title', url: '/settings/recently-deleted' },
    { key: 'mcp.connected_apps', url: '/settings/connected-apps' },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const t = useT();

    return (
        <div className="px-4 py-6">
            <Heading title={t('settings.title')} description={t('settings.manage')} />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {t(item.key)}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
