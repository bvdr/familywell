import { cn } from '@/lib/utils';
import { Wallet, type LucideProps } from 'lucide-react';
import { DynamicIcon, iconNames, type IconName } from 'lucide-react/dynamic';

// The full lucide set as a lookup, so validating/searching an icon key is O(1)/one pass. `iconNames`
// is lucide's own list of every icon's kebab-case key ('wallet', 'car-front', ...) — the same keys the
// server validates against (see modules/spending/lucide-icon-names.php).
const ICON_NAME_SET = new Set<string>(iconNames);

/** Every lucide icon key (kebab-case). The IconPicker searches this list. */
export const LUCIDE_ICON_NAMES: readonly string[] = iconNames;

/** True when `name` is a real lucide icon key. Guards rendering and picker input. */
export function isLucideIconName(name?: string | null): name is IconName {
    return !!name && ICON_NAME_SET.has(name);
}

type LucideIconProps = { name?: string | null } & Omit<LucideProps, 'ref' | 'name'>;

// Renders ANY lucide icon from its stored kebab-case key. Uses lucide's DynamicIcon so only the icons
// actually rendered are pulled in as lazy chunks — importing the whole set statically would add
// hundreds of KB to the bundle. An unknown/empty key renders Wallet, the spending module's historical
// default, so a list with no icon (or a legacy/removed key) still shows something sensible.
//
// During the async load DynamicIcon shows its `fallback`; we pass a same-sized Wallet so there's no
// layout jump and SSR (where the effect never runs) renders a stable Wallet that the client matches.
export function LucideIcon({ name, className, ...props }: LucideIconProps) {
    if (!isLucideIconName(name)) {
        return <Wallet className={cn('h-4 w-4', className)} {...props} />;
    }
    return (
        <DynamicIcon
            name={name}
            className={cn('h-4 w-4', className)}
            fallback={() => <Wallet className={cn('h-4 w-4', className)} {...props} />}
            {...props}
        />
    );
}
