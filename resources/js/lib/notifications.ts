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
    if (mins < 1) return 'now';
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    const days = Math.floor(hrs / 24);
    if (days < 7) return `${days}d`;
    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

// CSRF-aware fire-and-forget POST (these endpoints return 204, outside Inertia's router).
async function post(url: string): Promise<void> {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    await fetch(url, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        credentials: 'same-origin',
    }).catch(() => {});
}

export const markRead = (id: string) => post(route('notifications.read', id));
export const markAllRead = () => post(route('notifications.readAll'));
