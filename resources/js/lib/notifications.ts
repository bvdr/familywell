import { appLocale, relativeWord } from '@/lib/i18n';
import { Bell, FolderKanban, ListChecks, ShoppingCart, UserPlus, Wallet, type LucideIcon } from 'lucide-react';

export type Notification = {
    id: string;
    title: string;
    body: string;
    url: string;
    category: string | null;
    categoryLabel: string | null;
    read: boolean;
    at: string;
};

// Category -> icon for the feed rows. Falls back to a bell for anything unknown.
const ICONS: Record<string, LucideIcon> = {
    tasks: ListChecks,
    shopping: ShoppingCart,
    spending: Wallet,
    projects: FolderKanban,
    sharing: UserPlus,
};
export const notificationIcon = (category: string | null): LucideIcon => (category && ICONS[category]) || Bell;

export function relativeTime(iso: string): string {
    const then = new Date(iso).getTime();
    const mins = Math.floor((Date.now() - then) / 60000);
    // Compact "5m" / "3h" / "2d" in the app language ("3 T." in German) via narrow unit formatting.
    const unit = (n: number, u: string) => new Intl.NumberFormat(appLocale(), { style: 'unit', unit: u, unitDisplay: 'narrow' }).format(n);
    if (mins < 1) return relativeWord(0, 'second');
    if (mins < 60) return unit(mins, 'minute');
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return unit(hrs, 'hour');
    const days = Math.floor(hrs / 24);
    if (days < 7) return unit(days, 'day');
    return new Date(iso).toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
}

// CSRF-aware fire-and-forget request (these endpoints return 204, outside Inertia's router).
async function send(method: 'POST' | 'DELETE', url: string): Promise<void> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    await fetch(url, {
        method,
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        credentials: 'same-origin',
    }).catch(() => {});
}

export const markRead = (id: string) => send('POST', route('notifications.read', id));
export const markAllRead = () => send('POST', route('notifications.readAll'));
// Swipe-to-clear: deletes the row server-side (scoped to the owner); the caller drops it from local state.
export const dismissNotification = (id: string) => send('DELETE', route('notifications.destroy', id));
