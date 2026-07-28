// Global family-member avatar colours. Each member gets a soft [background, foreground] pastel pair.
// Colours are assigned per family by member order (the server shares a userId -> index map), so every
// member gets a DIFFERENT colour and it's the SAME colour everywhere that member appears. People who
// aren't family members (e.g. spending guests) fall back to a stable hash of their name.
export const AVATAR_PALETTE: [string, string][] = [
    ['#DCE6F5', '#2C5B8A'], // blue
    ['#E6F0E0', '#2D5E3A'], // green
    ['#F3E4DA', '#9A5B33'], // tan
    ['#F6E1DC', '#C2453A'], // red/coral
    ['#EFE6F5', '#6B4E8A'], // purple
];

const hash = (s: string) => [...s].reduce((a, c) => a + c.charCodeAt(0), 0);

// Name-hash colour, for people with no assigned family colour (guests, or anywhere the userId is unknown).
export const avatarColorByName = (name: string): [string, string] => AVATAR_PALETTE[hash(name || '?') % AVATAR_PALETTE.length];

// Colour by explicit palette slot (spending assigns per-participant position, incl. guests).
export const avatarColorByIndex = (i: number): [string, string] =>
    AVATAR_PALETTE[((i % AVATAR_PALETTE.length) + AVATAR_PALETTE.length) % AVATAR_PALETTE.length];

// The distinct-per-member colour: the family order index when the member is known, else a name hash.
export function avatarColorFor(map: Record<string, number> | undefined, userId: number | null | undefined, name: string): [string, string] {
    if (map && userId != null && String(userId) in map) return avatarColorByIndex(map[String(userId)]);
    return avatarColorByName(name);
}
