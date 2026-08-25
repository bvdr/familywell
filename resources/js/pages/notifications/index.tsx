import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { useState } from 'react';

import AppLayout from '@/layouts/app-layout';
import { useLocale, useT, type TFn } from '@/lib/i18n';
import { markAllRead, markRead, notificationIcon, relativeTime, type Notification } from '@/lib/notifications';
import { type BreadcrumbItem } from '@/types';

// Group by calendar day for readable section headers.
function dayLabel(iso: string, t: TFn, locale: string): string {
    const d = new Date(iso);
    const today = new Date();
    const y = new Date();
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return t('notifications.today');
    if (d.toDateString() === y.toDateString()) return t('notifications.yesterday');
    return d.toLocaleDateString(locale, { weekday: 'long', month: 'short', day: 'numeric' });
}

export default function NotificationsIndex({ feed = [] }: { feed?: Notification[] }) {
    // Default to [] so a missing/older prop shape shows the empty state instead of crashing to blank.
    const t = useT();
    const locale = useLocale();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('notifications.title'), href: '/notifications' }];
    const [items, setItems] = useState<Notification[]>(feed ?? []);
    const unread = items.filter((n) => !n.read).length;

    const openItem = (n: Notification) => {
        if (!n.read) {
            void markRead(n.id);
            setItems((list) => list.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
        }
        router.visit(n.url);
    };
    const clearAll = () => {
        void markAllRead();
        setItems((list) => list.map((x) => ({ ...x, read: true })));
    };

    // Preserve order (already newest-first) while grouping by day.
    const groups: { label: string; rows: Notification[] }[] = [];
    for (const n of items) {
        const label = dayLabel(n.at, t, locale);
        const last = groups[groups.length - 1];
        if (last && last.label === label) last.rows.push(n);
        else groups.push({ label, rows: [n] });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('notifications.title')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-2xl">{t('notifications.title')}</h1>
                    {unread > 0 && (
                        <button
                            type="button"
                            onClick={clearAll}
                            className="text-muted-foreground hover:text-primary flex items-center gap-1.5 text-sm font-medium"
                        >
                            <CheckCheck className="size-4" /> {t('notifications.mark_all_read')}
                        </button>
                    )}
                </div>

                {items.length === 0 && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <Bell className="size-6" />
                        <p className="text-sm">{t('notifications.empty')}</p>
                    </div>
                )}

                {groups.map((g) => (
                    <div key={g.label} className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{g.label}</h2>
                        <div className="bg-surface-card divide-border/60 flex flex-col divide-y rounded-2xl border">
                            {g.rows.map((n) => {
                                const Icon = notificationIcon(n.category);
                                return (
                                    <button
                                        key={n.id}
                                        type="button"
                                        onClick={() => openItem(n)}
                                        className={`hover:bg-surface flex items-start gap-3 px-4 py-3 text-left transition-colors ${n.read ? '' : 'bg-accent-subtle/40'}`}
                                    >
                                        <span className="bg-accent-subtle text-primary mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg">
                                            <Icon className="size-4.5" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-primary text-sm font-medium">{n.title}</div>
                                            {n.body && <div className="text-muted-foreground text-xs">{n.body}</div>}
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <span className="text-muted-foreground text-[11px]">{relativeTime(n.at)}</span>
                                            {!n.read && <span className="bg-accent-strong size-2 rounded-full" />}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
