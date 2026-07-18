import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Blocks, LayoutGrid, Users, Wallet } from 'lucide-react';

// The DS floating mobile navigation: a translucent, blurred pill that sits above the
// content with a soft shadow. Active tab gets an accent-subtle well + accent icon/label.
// Desktop uses the sidebar instead, so this is md:hidden.
const baseItems: NavItem[] = [
    { title: 'Home', url: '/dashboard', icon: LayoutGrid },
    { title: 'Members', url: '/family/members', icon: Users },
    { title: 'Modules', url: '/family/modules', icon: Blocks },
];

const moduleItems: Record<string, NavItem> = {
    spending: { title: 'Spending', url: '/m/spending', icon: Wallet },
};

export function MobileTabBar() {
    const page = usePage<{ modules?: { enabled?: string[] } }>();
    const enabled = page.props.modules?.enabled ?? [];
    const items = [...baseItems, ...enabled.map((id) => moduleItems[id]).filter(Boolean)];

    return (
        <nav className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex justify-center px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:hidden">
            <div className="border-border/60 pointer-events-auto flex w-full max-w-md items-center justify-around gap-1 rounded-full border bg-white/75 p-1.5 shadow-[var(--shadow-fw-md)] backdrop-blur-xl">
                {items.map((item) => {
                    // Match on prefix so a sub-page (e.g. a single spend event) keeps its tab lit.
                    const active = page.url === item.url || page.url.startsWith(item.url + '/');
                    return (
                        <Link
                            key={item.url}
                            href={item.url}
                            className={`flex flex-1 flex-col items-center gap-0.5 rounded-full py-1.5 text-[10px] font-medium transition-colors ${
                                // --accent is the pale hover-green in this app; the forest green is --primary.
                                active ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'
                            }`}
                        >
                            {item.icon && <item.icon className="size-5.5" />}
                            {item.title}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
