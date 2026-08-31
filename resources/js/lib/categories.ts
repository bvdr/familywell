import { BedDouble, Car, GraduationCap, HeartPulse, MoreHorizontal, ShoppingBag, Tag, Ticket, Utensils, type LucideIcon } from 'lucide-react';

import type { TFn } from '@/lib/i18n';

// A family-definable expense category, as sent by the server. `icon` is a lucide component name and
// `color` a hex string; the label is resolved client-side (see catLabel) so defaults stay localised.
export type CategoryDef = { key: string; name: string; icon: string; color: string; isDefault: boolean };

// Built-in categories store a PascalCase lucide COMPONENT name ('Utensils') resolved from this small
// static map. Custom categories instead store a kebab-case lucide KEY (e.g. 'party-popper') chosen via
// the IconPicker and rendered by the dynamic renderer — see <CategoryIcon>, which bridges the two. Tag
// stays here so legacy custom rows saved with the old PascalCase 'Tag' fallback still resolve.
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

// Resolve a PascalCase default-category icon name to its static lucide component, or undefined when the
// name isn't one of the built-in set. <CategoryIcon> uses this to pick between the static map (defaults)
// and the dynamic renderer (custom kebab-case keys).
export const staticCatIcon = (name?: string | null): LucideIcon | undefined => ICONS[name ?? ''];

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

// The stored icon string for a category key (PascalCase default name or kebab custom key), or undefined
// for an unknown/legacy key. Pass it straight to <CategoryIcon icon={...} />.
export const catIconName = (map: CategoryMap, key: string): string | undefined => map[key]?.icon;
