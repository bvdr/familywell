import { LucideIcon } from '@/components/lucide-icon';
import { cn } from '@/lib/utils';

// The icons a day to remember can have (design A3b / A3c): 20 to start, 45 with "More icons". All lucide keys
// except `cross` and `star-of-david`, which lucide draws differently (or not at all), so they're the design's own
// paths. No search: all 45 fit in one view.
export const ICON_GROUPS = {
    quiet: ['flower-2', 'flame', 'leaf', 'bird', 'dog', 'cat', 'moon', 'star', 'hand-heart', 'cross'],
    good: ['heart', 'gem', 'party-popper', 'house', 'baby', 'graduation-cap', 'plane', 'trophy', 'gift', 'paw-print'],
    faith: ['star-of-david', 'moon-star', 'church', 'flame-kindling', 'book-open'],
    nature: ['tree-deciduous', 'sprout', 'mountain', 'waves', 'sun', 'rainbow', 'cloud', 'snowflake', 'clover', 'fish'],
    everyday: ['car', 'music', 'camera', 'coffee', 'bike', 'feather', 'anchor', 'briefcase', 'tent', 'palette'],
} as const;
export type IconGroup = keyof typeof ICON_GROUPS;

/** The groups to show: this day's feeling first (A3b), then the rest of the full set (A3c). */
export function iconsFor(tone: 'celebrate' | 'quiet', more: boolean): IconGroup[] {
    const first: IconGroup[] = tone === 'quiet' ? ['quiet', 'good'] : ['good', 'quiet'];
    return more ? [...first, 'faith', 'nature', 'everyday'] : first;
}

// The calendar's "Everyday" row (design C13): the icons events most often want, whatever the title says.
export const EVENT_ICONS = ['cake', 'gift', 'party-popper', 'flag', 'graduation-cap', 'stethoscope', 'plane', 'car', 'house', 'trophy'] as const;

const CUSTOM: Record<string, string> = {
    cross: 'M12 3v18m-5.5-12.5h11',
    'star-of-david': 'M12 2.5l8.4 14.5h-16.8z m0 19l-8.4-14.5h16.8z',
};

/** True for the icons drawn here (cross, star-of-david), which no other lucide-rendering screen can show. */
export const isDrawnIcon = (name: string) => name in CUSTOM;

export function DayIcon({ name, className }: { name: string; className?: string }) {
    const path = CUSTOM[name];
    if (path) {
        return (
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
                className={cn('size-4', className)}
                aria-hidden
            >
                <path d={path} />
            </svg>
        );
    }
    return <LucideIcon name={name} className={className} aria-hidden />;
}

// One picker row of icons (the Days sheet's A3b/A3c, the calendar's C13): 5 to a row, the chosen one marked. Tapping
// one hands its key to the caller, which goes straight back to the form.
export function IconGrid({ icons, value, onPick }: { icons: readonly string[]; value: string | null; onPick: (name: string) => void }) {
    return (
        <div className="grid grid-cols-5 gap-2">
            {icons.map((name) => (
                <button
                    key={name}
                    type="button"
                    aria-label={name}
                    aria-pressed={value === name}
                    onClick={() => onPick(name)}
                    className={`flex h-12.5 items-center justify-center rounded-[14px] ${value === name ? 'bg-sun-soft border-ink border-2' : 'bg-card border'}`}
                >
                    <DayIcon name={name} className="text-ink size-5.5" />
                </button>
            ))}
        </div>
    );
}
