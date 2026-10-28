import { Head, Link, router } from '@inertiajs/react';
import { Bell, ChevronLeft, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import { MemberAvatar } from '@/components/member-avatar';
import AppLayout from '@/layouts/app-layout';
import { appLocale, useLocale, useT, type TFn } from '@/lib/i18n';
import { dismissNotification, markAllRead, markRead, type Notification } from '@/lib/notifications';
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

// A row's time-of-day ("9:12 am"), not a relative "5m": once rows are already grouped by day a clock
// time is more useful than a rolling relative one (the bell panel, which isn't grouped, keeps relativeTime()).
const rowTime = (iso: string) => new Date(iso).toLocaleTimeString(appLocale(), { hour: 'numeric', minute: '2-digit' });

// Best-effort actor name for the row avatar: every Notify::toAccessOf body is written "{actor->name} did
// X" (see modules/*/*.php call sites), so the leading word is the actor's first name.
// ponytail: this is an approximation, not a real user id — MemberAvatar falls back to a name-hash colour
// instead of the person's assigned family colour. A precise version needs an actorUserId threaded through
// every Notify:: call site (~15 files across every module); only worth doing if this ever visibly clashes.
const actorName = (body: string) => body.match(/^(\S+)/)?.[1] ?? '?';

const SWIPE_OPEN = 64; // px the row slides to reveal the delete affordance
const SWIPE_THRESHOLD = 48; // px of drag past which releasing dismisses instead of snapping back

function NotificationRow({
    n,
    onOpen,
    onDismiss,
    t,
}: {
    n: Notification;
    onOpen: (n: Notification) => void;
    onDismiss: (n: Notification) => void;
    t: TFn;
}) {
    const [dragX, setDragX] = useState(0);
    const [dragging, setDragging] = useState(false);
    const startX = useRef(0);

    const onPointerDown = (e: React.PointerEvent) => {
        startX.current = e.clientX;
        setDragging(true);
        e.currentTarget.setPointerCapture(e.pointerId);
    };
    const onPointerMove = (e: React.PointerEvent) => {
        if (!dragging) return;
        setDragX(Math.max(-SWIPE_OPEN, Math.min(0, e.clientX - startX.current)));
    };
    const endDrag = () => {
        setDragging(false);
        if (dragX <= -SWIPE_THRESHOLD) onDismiss(n);
        setDragX(0);
    };

    return (
        <div className="relative overflow-hidden">
            {/* Swipe-reveal delete affordance, behind the row. Also the touch target once it's exposed. */}
            <button
                type="button"
                onClick={() => onDismiss(n)}
                aria-hidden={dragX > -SWIPE_THRESHOLD}
                tabIndex={-1}
                className="bg-destructive text-destructive-foreground absolute inset-y-0 right-0 flex w-16 items-center justify-center"
            >
                <Trash2 className="size-4" />
            </button>
            <div
                role="button"
                tabIndex={0}
                onClick={() => dragX === 0 && onOpen(n)}
                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && onOpen(n)}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                style={{ transform: `translateX(${dragX}px)`, touchAction: 'pan-y' }}
                className={`group hover:bg-surface flex items-start gap-3 px-4 py-3 text-left transition-colors ${dragging ? '' : 'duration-200'} ${n.read ? 'bg-surface-card' : 'bg-accent-subtle'}`}
            >
                <MemberAvatar name={actorName(n.body)} className="size-9 shrink-0 text-xs" />
                <div className="min-w-0 flex-1">
                    <div className="text-primary text-sm font-semibold">{n.body}</div>
                    <div className="text-muted-foreground mt-0.5 text-xs">
                        {n.title} · {rowTime(n.at)}
                    </div>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1 pt-0.5">
                    {!n.read && <span className="bg-accent-strong size-2.5 rounded-full" />}
                    {/* Non-swipe fallback for desktop / keyboard / screen readers: hidden until hover or focus,
                        real <button> (nested inside a <div role="button">, not another <button>, so it stays
                        independently focusable and doesn't trigger onOpen via bubbling). */}
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onDismiss(n);
                        }}
                        aria-label={t('notifications.dismiss')}
                        className="text-muted-foreground hover:text-destructive opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
                    >
                        <Trash2 className="size-3.5" />
                    </button>
                </div>
            </div>
        </div>
    );
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
    const dismiss = (n: Notification) => {
        void dismissNotification(n.id);
        setItems((list) => list.filter((x) => x.id !== n.id));
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
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-center justify-between">
                    <Link
                        href="/dashboard"
                        className="border-border bg-surface-card text-primary inline-flex items-center gap-1 rounded-full border py-1.5 pr-3.5 pl-2.5 text-sm font-medium"
                    >
                        <ChevronLeft className="size-4" /> {t('nav.home')}
                    </Link>
                    <Link
                        href="/settings/notifications"
                        aria-label={t('notifications.settings')}
                        className="border-border bg-surface-card text-primary flex size-9 items-center justify-center rounded-full border"
                    >
                        <SlidersHorizontal className="size-4" />
                    </Link>
                </div>

                <div className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-3xl font-black">{t('notifications.title')}</h1>
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm font-medium">
                            {unread > 0 ? t('notifications.new_count', { count: unread }) : ''}
                        </span>
                        {unread > 0 && (
                            <button type="button" onClick={clearAll} className="text-primary text-sm font-semibold">
                                {t('notifications.mark_all_read')}
                            </button>
                        )}
                    </div>
                </div>

                {items.length === 0 && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <Bell className="size-6" />
                        <p className="text-sm">{t('notifications.empty')}</p>
                    </div>
                )}

                {groups.map((g) => (
                    <div key={g.label} className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">{g.label}</h2>
                        <div className="bg-surface-card divide-border/60 flex flex-col divide-y overflow-hidden rounded-2xl border">
                            {g.rows.map((n) => (
                                <NotificationRow key={n.id} n={n} onOpen={openItem} onDismiss={dismiss} t={t} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
