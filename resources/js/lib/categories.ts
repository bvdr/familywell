import { BedDouble, Car, MoreHorizontal, ShoppingBag, Ticket, Utensils, type LucideIcon } from 'lucide-react';

// Global icon pack for spend categories: single source of truth so every surface
// (pickers, spend log, guest/public views) draws the same icon for a category.
// lucide-react is the app-wide icon library; add new categories here only.
export const CATEGORY_ICONS: Record<string, LucideIcon> = {
    food: Utensils,
    transport: Car,
    lodging: BedDouble,
    activities: Ticket,
    shopping: ShoppingBag,
    other: MoreHorizontal,
};

// Fallback keeps unknown/legacy categories rendering instead of blowing up.
export const categoryIcon = (key: string): LucideIcon => CATEGORY_ICONS[key] ?? MoreHorizontal;
