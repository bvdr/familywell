import { BedDouble, Car, GraduationCap, HeartPulse, MoreHorizontal, ShoppingBag, Tag, Ticket, Utensils, type LucideIcon } from 'lucide-react';

import type { TFn } from '@/lib/i18n';

// A family-definable expense category, as sent by the server. `icon` is a lucide component name and
// `color` a hex string; the label is resolved client-side (see catLabel) so defaults stay localised.
export type CategoryDef = { key: string; name: string; icon: string; color: string; isDefault: boolean };

// Lucide icon components addressable by the `icon` name stored on a category. Custom categories always
// use Tag, so this only needs the default set plus the fallback.
const ICONS: Record<string, LucideIcon> = {
    Utensils,
    Car,
    BedDouble,
    Ticket,
    ShoppingBag,
    GraduationCap,
    HeartPulse,
    MoreHorizontal,
    Tag,
};

// Fallback keeps unknown/legacy icon names (and custom categories) rendering instead of blowing up.
export const iconByName = (name?: string | null): LucideIcon => ICONS[name ?? ''] ?? Tag;

// Fallback colour for a category key that no longer resolves (e.g. a deleted category still on a spend).
export const FALLBACK_COLOR = '#9A8C7A';

export type CategoryMap = Record<string, CategoryDef>;

// Index a category list by key for O(1) lookup when rendering a spend's category.
export const categoryMap = (cats: CategoryDef[]): CategoryMap => Object.fromEntries(cats.map((c) => [c.key, c]));

// Label: defaults resolve via i18n (spending.category_<key>), custom ones use the stored name, and an
// unknown/legacy key falls back to a capitalised version of the key itself.
export const catLabel = (t: TFn, map: CategoryMap, key: string): string => {
    const c = map[key];
    if (!c) return key.charAt(0).toUpperCase() + key.slice(1);
    return c.isDefault ? t('spending.category_' + key) : c.name;
};

export const catColor = (map: CategoryMap, key: string): string => map[key]?.color ?? FALLBACK_COLOR;

export const catIcon = (map: CategoryMap, key: string): LucideIcon => iconByName(map[key]?.icon);
