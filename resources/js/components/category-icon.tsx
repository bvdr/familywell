import { Tag, type LucideProps } from 'lucide-react';

import { LucideIcon } from '@/components/lucide-icon';
import { staticCatIcon } from '@/lib/categories';

type CategoryIconProps = { icon?: string | null } & Omit<LucideProps, 'ref' | 'name'>;

// Renders a category's icon from its stored `icon` string, bridging the two icon conventions in play:
// built-in categories store a PascalCase lucide component name ('Utensils') resolved from a small static
// map, while custom categories store a kebab-case lucide key ('party-popper') chosen via the IconPicker
// and rendered with the shared <LucideIcon>. Anything unrecognised falls back to Tag, the generic custom-category
// icon — so a custom category with no chosen icon still renders sensibly. The unknown-key check happens inside
// LucideIcon (its `fallback`), so this doesn't import lucide's full name list onto every spending page.
export function CategoryIcon({ icon, ...props }: CategoryIconProps) {
    const Static = staticCatIcon(icon); // PascalCase built-in (or legacy 'Tag') → static component
    if (Static) return <Static {...props} />;
    return <LucideIcon name={icon} fallback={<Tag {...props} />} {...props} />; // kebab custom key; unknown/empty → Tag
}
