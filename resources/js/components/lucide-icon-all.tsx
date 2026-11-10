import { type LucideProps } from 'lucide-react';
import { DynamicIcon, iconNames, type IconName } from 'lucide-react/dynamic';
import { type ReactNode } from 'react';

// The heavy half of <LucideIcon>: the whole lucide set. Importing anything from 'lucide-react/dynamic' pulls lucide's
// import map for every icon (`iconNames` is just Object.keys of it, ~47KB gz), so only reach this module lazily
// (LucideIcon's React.lazy, IconPicker's import() on open) or from screens that really need the full list (the family
// symbol sheet). Ordinary rendering goes through LucideIcon's static map and never loads this.

// The full lucide set as a lookup, so validating/searching an icon key is O(1)/one pass. `iconNames` is lucide's own
// list of every icon's kebab-case key ('wallet', 'car-front', ...) — the same keys the server validates against
// (see modules/spending/lucide-icon-names.php).
const ICON_NAME_SET = new Set<string>(iconNames);

/** Every lucide icon key (kebab-case). The icon pickers search this list. */
export const LUCIDE_ICON_NAMES: readonly string[] = iconNames;

/** True when `name` is a real lucide icon key. Guards picker input. */
export function isLucideIconName(name?: string | null): name is IconName {
    return !!name && ICON_NAME_SET.has(name);
}

// Default export for LucideIcon's React.lazy: an icon outside its static map. DynamicIcon fetches the icon's own
// chunk and shows `fallback` meanwhile; an unknown key (legacy/removed) shows `fallback` for good.
export default function AnyLucideIcon({ name, fallback, ...props }: { name: string; fallback: ReactNode } & Omit<LucideProps, 'ref' | 'name'>) {
    if (!isLucideIconName(name)) return <>{fallback}</>;
    return <DynamicIcon name={name} fallback={() => <>{fallback}</>} {...props} />;
}
