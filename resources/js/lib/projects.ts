// Status metadata for projects and dependencies: labels + chip classes, single source of truth so
// the index cards, detail hero, and pickers agree. Uses the app's tokens (primary = forest green).
export const PROJECT_STATUS: Record<string, { label: string; chip: string; hero: string }> = {
    planning: { label: 'Planning', chip: 'bg-surface text-muted-foreground', hero: 'bg-white/85 text-primary' },
    in_progress: { label: 'In progress', chip: 'bg-accent-subtle text-primary', hero: 'bg-accent-strong text-accent-strong-foreground' },
    blocked: { label: 'Blocked', chip: 'bg-amber-100 text-amber-700', hero: 'bg-amber-500 text-white' },
    done: { label: 'Done', chip: 'bg-accent-strong text-accent-strong-foreground', hero: 'bg-accent-strong text-accent-strong-foreground' },
};

// Deterministic per-member avatar colour (bg, fg), matching the design's coloured initials.
const AVATAR_PALETTE: [string, string][] = [
    ['#DCE6F5', '#2C5B8A'],
    ['#E6F0E0', '#2D5E3A'],
    ['#F3E4DA', '#9A5B33'],
    ['#F6E1DC', '#C2453A'],
    ['#EFE6F5', '#6B4E8A'],
];
export const avatarColor = (name: string): [string, string] =>
    AVATAR_PALETTE[[...name].reduce((a, c) => a + c.charCodeAt(0), 0) % AVATAR_PALETTE.length];

// "2026-08-01" -> "Aug 1"
export const shortDate = (iso: string | null): string => {
    if (!iso) return '—';
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};
export const projectStatus = (s: string) => PROJECT_STATUS[s] ?? PROJECT_STATUS.planning;
export const PROJECT_STATUS_KEYS = Object.keys(PROJECT_STATUS);

export const DEP_STATUS: Record<string, { label: string; chip: string; dot: string }> = {
    pending: { label: 'Pending', chip: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
    blocked: { label: 'Blocked', chip: 'bg-red-100 text-red-700', dot: 'bg-red-500' },
    done: { label: 'Done', chip: 'bg-accent-subtle text-primary', dot: 'bg-accent-strong' },
};
export const depStatus = (s: string) => DEP_STATUS[s] ?? DEP_STATUS.pending;
export const DEP_STATUS_KEYS = Object.keys(DEP_STATUS);
