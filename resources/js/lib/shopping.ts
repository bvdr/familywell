import {
    Beef,
    Carrot,
    Croissant,
    CupSoda,
    Gift,
    Home,
    Milk,
    Package,
    PartyPopper,
    ShoppingBasket,
    ShoppingCart,
    Snowflake,
    SprayCan,
    Store,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

// Global icon pack for grocery aisles: single source of truth so pickers, list detail and
// focus mode all draw the same icon + label for a department. Backend validates the slugs
// (ShoppingController::DEPARTMENTS); grouping order follows the `departments` array it sends.
export const AISLES: Record<string, { label: string; icon: LucideIcon }> = {
    produce: { label: 'Produce', icon: Carrot },
    dairy: { label: 'Dairy & Eggs', icon: Milk },
    bakery: { label: 'Bakery', icon: Croissant },
    pantry: { label: 'Pantry', icon: Package },
    household: { label: 'Household', icon: SprayCan },
    frozen: { label: 'Frozen', icon: Snowflake },
    meat: { label: 'Meat', icon: Beef },
    beverages: { label: 'Beverages', icon: CupSoda },
    other: { label: 'Other', icon: ShoppingBasket },
};

export const aisle = (slug: string) => AISLES[slug] ?? AISLES.other;

// Per-list icon (chosen on the New List screen). Default 'cart'.
export const LIST_ICONS: Record<string, LucideIcon> = {
    cart: ShoppingCart,
    party: PartyPopper,
    tools: Wrench,
    gift: Gift,
    home: Home,
    store: Store,
};

export const listIcon = (slug: string) => LIST_ICONS[slug] ?? ShoppingCart;

// Per-list colour swatch → tile classes (bg + icon colour) for the list card and detail header.
export const LIST_COLORS: Record<string, string> = {
    green: 'bg-accent-subtle text-primary',
    amber: 'bg-amber-100 text-amber-700',
    red: 'bg-red-100 text-red-700',
    blue: 'bg-blue-100 text-blue-700',
    purple: 'bg-purple-100 text-purple-700',
};

export const listColor = (slug: string) => LIST_COLORS[slug] ?? LIST_COLORS.green;

// Voice dictation language. The Web Speech API can't auto-detect the spoken language, so the user
// picks one on the module Settings page. Stored per-device (it's a mic/input preference), default
// Romanian. Shared here so both the Settings page and the voice screen agree on the key + options.
export const VOICE_LANGS = [
    { code: 'ro-RO', label: 'Română' },
    { code: 'en-US', label: 'English' },
];
const VOICE_LANG_KEY = 'familywell:voiceLang';
export const getVoiceLang = (): string => (typeof window === 'undefined' ? 'ro-RO' : localStorage.getItem(VOICE_LANG_KEY) || 'ro-RO');
export const setVoiceLang = (code: string): void => {
    try {
        localStorage.setItem(VOICE_LANG_KEY, code);
    } catch {
        /* private mode: fall back to session default */
    }
};
