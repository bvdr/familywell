import { localToday } from '@/lib/date-groups';
import { appLocale, relativeWord } from '@/lib/i18n';
import { listIconFor } from '@/lib/list-icons';

import { Briefcase, CalendarDays, CheckSquare, Heart, Home, ListChecks, Plane, ShoppingBag, Sparkles, Star, type LucideIcon } from 'lucide-react';

// Per-list icon, chosen on the New List screen. Default 'list'.
export const LIST_ICONS: Record<string, LucideIcon> = {
    list: ListChecks,
    check: CheckSquare,
    home: Home,
    work: Briefcase,
    trip: Plane,
    calendar: CalendarDays,
    heart: Heart,
    shop: ShoppingBag,
    star: Star,
    sparkles: Sparkles,
};

// Any icon a list can have (lib/list-icons: the New list screen's full set), not only the ten above.
export const listIcon = (slug: string) => listIconFor(slug);
export const LIST_ICON_KEYS = Object.keys(LIST_ICONS);

// Per-list colour swatch -> tile classes (bg + icon colour). Same palette as the shopping module.
export const LIST_COLORS: Record<string, string> = {
    green: 'bg-accent-subtle text-primary',
    amber: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-blue-100 text-blue-700',
    purple: 'bg-purple-100 text-purple-700',
    // The New list screen's colours (design j6QDy4 T3c): sun yellow and plain sand, with the four above.
    yellow: 'bg-accent-strong text-accent-strong-foreground',
    sand: 'bg-surface text-primary',
};
// The swatches the New list screen offers, in the design's order.
export const NEW_LIST_COLORS = ['yellow', 'green', 'red', 'blue', 'amber', 'sand'];

export const listColor = (slug: string) => LIST_COLORS[slug] ?? LIST_COLORS.green;
export const LIST_COLOR_KEYS = Object.keys(LIST_COLORS);
// Tile/bar classes for an optional colour (calendar items/events): the palette, or the neutral tile token when unset.
export const itemTone = (color: string | null) => (color ? listColor(color) : 'bg-tile text-tile-foreground');

// A due date rendered as a short label + urgency tone for the chip. Null => no due date.
export function dueLabel(iso: string | null): { label: string; tone: string } | null {
    if (!iso) return null;
    const due = new Date(iso + 'T00:00:00');
    // "Today" is the family's (localToday), the day the server's overdue and Home's Today use.
    const today = new Date(localToday() + 'T00:00:00');
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    const short = due.toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
    // overdue / today -> red, next couple of days -> amber, further out -> muted.
    if (days < 0) return { label: short, tone: 'bg-red-100 text-red-700' };
    if (days === 0) return { label: relativeWord(0, 'day'), tone: 'bg-red-100 text-red-700' };
    if (days === 1) return { label: relativeWord(1, 'day'), tone: 'bg-amber-100 text-amber-700' };
    if (days <= 3) return { label: short, tone: 'bg-amber-100 text-amber-700' };
    return { label: short, tone: 'bg-surface text-muted-foreground' };
}

// 'YYYY-MM-DD' of a moment in the browser's day (for "Yesterday" / weekday labels).
const isoDay = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

// "When it last changed", as the design writes it: 12 min ago, 2 h ago, Yesterday, Mon, Oct 14.
export function ago(iso: string): string {
    const then = new Date(iso);
    const mins = Math.max(0, Math.floor((Date.now() - then.getTime()) / 60000));
    const rtf = new Intl.RelativeTimeFormat(appLocale(), { numeric: 'auto', style: 'short' });
    if (mins < 60) return rtf.format(-mins, 'minute');
    if (mins < 24 * 60) return rtf.format(-Math.floor(mins / 60), 'hour');
    const days = Math.round((new Date(isoDay(new Date())).getTime() - new Date(isoDay(then)).getTime()) / 864e5);
    if (days === 1) return relativeWord(-1, 'day');
    if (days < 7) return then.toLocaleDateString(appLocale(), { weekday: 'short' });
    return then.toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
}

// A repeat rhythm in a row's meta line ("every 3 months", "every 2 weeks"), the Repeat add-on's wording
// (recurring_todos.every_*, same rules as its rhythmLabel) with a small first letter.
export function rhythmText(t: (key: string, params?: Record<string, string | number>) => string, v: string): string {
    const m = /^(\d{1,3}):(day|week|month)$/.exec(v);
    const s = m ? t(`recurring_todos.every_${m[2]}`, { count: Number(m[1]) }) : t(`recurring_todos.every_${v}`);
    return s.charAt(0).toLocaleLowerCase(appLocale()) + s.slice(1);
}
