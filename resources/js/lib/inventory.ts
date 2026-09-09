import type { TFn } from '@/lib/i18n';

// The item shape the server sends (InventoryItem::toPayload). `quantity` null is the "unknown" state;
// `state` and `bar` are derived server-side so the client never re-implements the rules.
export type InvState = 'good' | 'low' | 'empty' | 'expiring' | 'expired' | 'unknown';

// The units a quantity can be measured in (mirrors InventoryItem::UNITS on the server).
export type InvUnit = 'pieces' | 'g' | 'kg' | 'ml' | 'L' | 'packs' | 'bottles' | 'boxes';

export type InvItem = {
    id: number;
    name: string;
    quantity: number | null;
    unit: string;
    category: string | null;
    location: string | null;
    shop: string | null; // shop NAME (as Shopping stores it), or null for none
    minQuantity: number;
    targetQuantity: number | null;
    expiresAt: string | null;
    autoReplenish: boolean;
    notes: string | null;
    // Shopping-loop link (P4). `onShoppingList` is true while the item has an open shopping row (so screens
    // show "Shopping · Added" instead of an Add button); `shoppingListId` deep-links to that list when known.
    onShoppingList: boolean;
    shoppingListId: number | null;
    state: InvState;
    stale: boolean; // fine on stock but gone untouched for a long time (soft nudge; orthogonal to state)
    bar: number | null; // 0..1 fill fraction, or null when there's nothing to measure against
    createdBy: number | null;
    updatedAt: string | null;
};

export type InvCategory = { key: string; name: string; icon: string; color: string; isDefault: boolean };
export type InvLocation = { key: string; name: string; icon: string; isDefault: boolean };

// Colour per state, drawn from the theme tokens so it tracks the active skin: green = healthy,
// amber = attention, red = urgent (empty/expired), grey = unknown. `tone` groups them for filters.
export const STATE_META: Record<InvState, { color: string; tone: 'good' | 'warn' | 'bad' | 'muted' }> = {
    good: { color: 'var(--success)', tone: 'good' },
    low: { color: 'var(--warning)', tone: 'warn' },
    expiring: { color: 'var(--warning)', tone: 'warn' },
    empty: { color: 'var(--destructive)', tone: 'bad' },
    expired: { color: 'var(--destructive)', tone: 'bad' },
    unknown: { color: 'var(--muted-foreground)', tone: 'muted' },
};

/** Localised state label (Good / Low / Empty / …). */
export const stateLabel = (t: TFn, state: InvState): string => t(`inventory.state_${state}`);

/**
 * Resolve a category's display label: seeded defaults are localised via i18n
 * (inventory.category_<key>); custom categories fall back to the name the admin typed. `map` is the
 * key => category lookup built from the page's categories payload.
 */
export const catLabel = (t: TFn, key: string | null, map: Record<string, InvCategory>): string => {
    if (!key) return t('inventory.no_category');
    const c = map[key];
    if (c && !c.isDefault) return c.name;
    return t(`inventory.category_${key}`);
};

export const locLabel = (t: TFn, key: string | null, map: Record<string, InvLocation>): string => {
    if (!key) return t('inventory.no_location');
    const l = map[key];
    if (l && !l.isDefault) return l.name;
    return t(`inventory.location_${key}`);
};

/** Localised unit abbreviation (pcs / g / kg / …); unknown units render as-is. */
export const unitLabel = (t: TFn, unit: string): string => {
    const key = `inventory.unit_${unit}`;
    const label = t(key);
    return label === key ? unit : label; // t() returns the key on a miss — fall back to the raw unit
};

/** "4 pcs" / "Unknown" — the quantity as shown next to an item. */
export const qtyText = (t: TFn, item: Pick<InvItem, 'quantity' | 'unit'>): string => {
    if (item.quantity === null) return t('inventory.qty_unknown');
    return `${fmtNumber(item.quantity)} ${unitLabel(t, item.unit)}`;
};

/** Trim a decimal to a clean display (12.00 -> "12", 1.50 -> "1.5"). */
export const fmtNumber = (n: number): string => {
    if (Number.isInteger(n)) return String(n);
    return String(Number(n.toFixed(2)));
};

/** Index category/location payload rows by their `key`, for O(1) label/icon lookups in a render. */
export const byKey = <T extends { key: string }>(rows: T[]): Record<string, T> =>
    Object.fromEntries(rows.map((r) => [r.key, r]));
