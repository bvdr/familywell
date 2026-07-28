import { usePage } from '@inertiajs/react';

import { avatarColorFor } from '@/lib/avatar';

// The family's userId -> colour-index map, shared globally by the server (HandleInertiaRequests).
export function useMemberColors(): Record<string, number> {
    return (usePage().props as { memberColors?: Record<string, number> }).memberColors ?? {};
}

// A function to colour any person consistently: their assigned family colour if a member, else a name hash.
export function useMemberColor() {
    const map = useMemberColors();
    return (userId: number | null | undefined, name: string) => avatarColorFor(map, userId, name);
}

// One person's avatar: a soft-coloured circle with their initial. Pass `userId` for a family member
// (gets their distinct family colour); guests/unknowns fall back to a stable name colour. `className`
// controls size/typography (default a 24px chip); `ring` adds the overlap ring for stacked avatars.
export function MemberAvatar({
    userId,
    name,
    className = 'size-6 text-[10px]',
    ring = false,
    title,
}: {
    userId?: number | null;
    name: string;
    className?: string;
    ring?: boolean;
    title?: string;
}) {
    const color = useMemberColor();
    const [bg, fg] = color(userId, name);
    return (
        <span
            title={title ?? name}
            style={{ background: bg, color: fg }}
            className={`inline-flex shrink-0 items-center justify-center rounded-full font-semibold ${ring ? 'ring-background ring-2' : ''} ${className}`}
        >
            {(name || '?').charAt(0).toUpperCase()}
        </span>
    );
}
