import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useRef, useState } from 'react';

import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { markAllRead, markRead, notificationIcon, relativeTime, type Notification } from '@/lib/notifications';
import { useT } from '@/lib/i18n';

type Shared = { unread: number; items: Notification[] };

export function NotificationsBell() {
    const shared = (usePage().props as { notifications?: Shared }).notifications ?? { unread: 0, items: [] };
    const t = useT();
    const serverItems = shared.items ?? [];
    const [open, setOpen] = useState(false);

    // Optimistic read-state is the ONLY local state, and it's mutated exclusively from user actions
    // (never from an effect syncing props). The previous version synced props -> state in a useEffect;
    // Inertia hands back a fresh `notifications` object on re-renders, so that effect re-ran every
    // render and looped forever (React #185, "Maximum update depth exceeded"). Deriving straight from
    // props removes the sync entirely, so no loop is possible on any browser.
    const [readIds, setReadIds] = useState<Set<string>>(() => new Set());
    const [clearedAll, setClearedAll] = useState(false);

    // When the server data actually changes (a primitive signature, not object identity), drop the
    // optimistic overrides so we mirror the server again. Guarded so it self-terminates (no loop):
    // after the reset the signature matches and the branch is skipped.
    const signature = `${shared.unread ?? 0}|${serverItems.map((n) => `${n.id}:${n.read ? 1 : 0}`).join(',')}`;
    const lastSignature = useRef(signature);
    if (lastSignature.current !== signature) {
        lastSignature.current = signature;
        if (readIds.size) setReadIds(new Set());
        if (clearedAll) setClearedAll(false);
    }

    const isRead = (n: Notification) => n.read || clearedAll || readIds.has(n.id);
    const items = serverItems.map((n) => (isRead(n) ? { ...n, read: true } : n));
    const locallyRead = serverItems.filter((n) => !n.read && (clearedAll || readIds.has(n.id))).length;
    const unread = clearedAll ? 0 : Math.max(0, (shared.unread ?? 0) - locallyRead);

    const openItem = (n: Notification) => {
        if (!isRead(n)) {
            void markRead(n.id);
            setReadIds((s) => new Set(s).add(n.id));
        }
        setOpen(false);
        router.visit(n.url);
    };
    const clearAll = () => {
        void markAllRead();
        setClearedAll(true);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={unread > 0 ? t('notifications.aria_unread', { n: unread }) : t('notifications.aria')}
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
                title={t('notifications.title')}
                footer={
                    <Link
                        href={route('notifications.index')}
                        onClick={() => setOpen(false)}
                        className="text-primary block w-full rounded-lg py-2 text-center text-sm font-medium"
                    >
                        {t('notifications.see_all')}
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
                            <CheckCheck className="size-3.5" /> {t('notifications.mark_all_read')}
                        </button>
                    )}
                    {items.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 py-10 text-center">
                            <Bell className="size-6" />
                            <p className="text-sm">{t('notifications.caught_up')}</p>
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
