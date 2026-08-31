import { Tag, type LucideProps } from 'lucide-react';

import { LucideIcon, isLucideIconName } from '@/components/lucide-icon';
import { staticCatIcon } from '@/lib/categories';

type CategoryIconProps = { icon?: string | null } & Omit<LucideProps, 'ref' | 'name'>;

// Renders a category's icon from its stored `icon` string, bridging the two icon conventions in play:
// built-in categories store a PascalCase lucide component name ('Utensils') resolved from a small static
// map, while custom categories store a kebab-case lucide key ('party-popper') chosen via the IconPicker
// and rendered with the shared dynamic renderer (DynamicIcon). Anything unrecognised falls back to Tag,
// the generic custom-category icon — so a custom category with no chosen icon still renders sensibly.
export function CategoryIcon({ icon, ...props }: CategoryIconProps) {
    const Static = staticCatIcon(icon); // PascalCase built-in (or legacy 'Tag') → static component
    if (Static) return <Static {...props} />;
    if (isLucideIconName(icon)) return <LucideIcon name={icon} {...props} />; // kebab custom key → dynamic
    return <Tag {...props} />; // unknown/empty → generic tag fallback
}
