import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { markAllRead, markRead, notificationIcon, relativeTime, type Notification } from '@/lib/notifications';

type Shared = { unread: number; items: Notification[] };

export function NotificationsBell() {
    const shared = (usePage().props as { notifications?: Shared }).notifications ?? { unread: 0, items: [] };
    const [open, setOpen] = useState(false);
    // Local mirror so tapping/mark-all updates the badge instantly; re-sync when the server prop changes.
    const [items, setItems] = useState<Notification[]>(shared.items ?? []);
    const [unread, setUnread] = useState(shared.unread ?? 0);
    useEffect(() => {
        setItems(shared.items ?? []);
        setUnread(shared.unread ?? 0);
    }, [shared.items, shared.unread]);

    const openItem = (n: Notification) => {
        if (!n.read) {
            void markRead(n.id);
            setItems((list) => list.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
            setUnread((u) => Math.max(0, u - 1));
        }
        setOpen(false);
        router.visit(n.url);
    };
    const clearAll = () => {
        void markAllRead();
        setItems((list) => list.map((x) => ({ ...x, read: true })));
        setUnread(0);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
                className="border-border text-primary hover:bg-surface relative flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <Bell className="size-4.5" />
                {unread > 0 && (
                    <span className="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full px-1 text-[10px] font-semibold">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            <ResponsiveModal
                open={open}
                onOpenChange={setOpen}
                title="Notifications"
                footer={
                    <Link
                        href={route('notifications.index')}
                        onClick={() => setOpen(false)}
                        className="text-primary block w-full rounded-lg py-2 text-center text-sm font-medium"
                    >
                        See all
                    </Link>
                }
            >
                <div className="flex flex-col">
                    {unread > 0 && (
                        <button
                            type="button"
                            onClick={clearAll}
                            className="text-muted-foreground hover:text-primary mb-1 ml-auto flex items-center gap-1.5 text-xs font-medium"
                        >
                            <CheckCheck className="size-3.5" /> Mark all read
                        </button>
                    )}
                    {items.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 py-10 text-center">
                            <Bell className="size-6" />
                            <p className="text-sm">You're all caught up.</p>
                        </div>
                    ) : (
                        <div className="-mx-2 flex flex-col">
                            {items.map((n) => {
                                const Icon = notificationIcon(n.category);
                                return (
                                    <button
                                        key={n.id}
                                        type="button"
                                        onClick={() => openItem(n)}
                                        className={`hover:bg-surface flex items-start gap-3 rounded-xl px-2 py-2.5 text-left transition-colors ${n.read ? '' : 'bg-accent-subtle/40'}`}
                                    >
                                        <span className="bg-accent-subtle text-primary mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg">
                                            <Icon className="size-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-primary truncate text-sm font-medium">{n.title}</div>
                                            {n.body && <div className="text-muted-foreground truncate text-xs">{n.body}</div>}
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <span className="text-muted-foreground text-[11px]">{relativeTime(n.at)}</span>
                                            {!n.read && <span className="bg-primary size-2 rounded-full" />}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            </ResponsiveModal>
        </>
    );
}
