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

export const listIcon = (slug: string) => LIST_ICONS[slug] ?? ListChecks;
export const LIST_ICON_KEYS = Object.keys(LIST_ICONS);

// Per-list colour swatch -> tile classes (bg + icon colour). Same palette as the shopping module.
export const LIST_COLORS: Record<string, string> = {
    green: 'bg-accent-subtle text-primary',
    amber: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-blue-100 text-blue-700',
    purple: 'bg-purple-100 text-purple-700',
};

export const listColor = (slug: string) => LIST_COLORS[slug] ?? LIST_COLORS.green;
export const LIST_COLOR_KEYS = Object.keys(LIST_COLORS);
// Tile/bar classes for an optional colour (calendar items/events): the palette, or the neutral tile token when unset.
export const itemTone = (color: string | null) => (color ? listColor(color) : 'bg-tile text-tile-foreground');

// A due date rendered as a short label + urgency tone for the chip. Null => no due date.
export function dueLabel(iso: string | null): { label: string; tone: string } | null {
    if (!iso) return null;
    const due = new Date(iso + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((due.getTime() - today.getTime()) / 86400000);
    const short = due.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    // overdue / today -> red, next couple of days -> amber, further out -> muted.
    if (days < 0) return { label: short, tone: 'bg-red-100 text-red-700' };
    if (days === 0) return { label: 'Today', tone: 'bg-red-100 text-red-700' };
    if (days === 1) return { label: 'Tomorrow', tone: 'bg-amber-100 text-amber-700' };
    if (days <= 3) return { label: short, tone: 'bg-amber-100 text-amber-700' };
    return { label: short, tone: 'bg-surface text-muted-foreground' };
}
