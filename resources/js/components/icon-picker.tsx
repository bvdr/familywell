import { useEffect, useMemo, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { LucideIcon, LUCIDE_ICON_NAMES } from '@/components/lucide-icon';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// How many icons we render at once. The lucide set is ~1700 icons and each grid cell lazy-loads its own
// chunk, so rendering them all would fire hundreds of requests and thrash layout. Capping to a screenful
// of matches keeps it snappy; the search box is how you reach anything past the cap.
const MAX_RESULTS = 90;

// A handful of broadly useful icons shown before the user types, so the grid is never empty. Picked to
// cover the common "what is this list about" cases (travel, food, home, money…). All are real lucide keys.
const SUGGESTED: readonly string[] = [
    'wallet', 'plane', 'car', 'utensils', 'shopping-cart', 'shopping-bag', 'house', 'gift', 'heart',
    'gamepad-2', 'baby', 'graduation-cap', 'briefcase', 'plug', 'wrench', 'stethoscope', 'pizza',
    'coffee', 'beer', 'shirt', 'dumbbell', 'party-popper', 'tree-palm', 'tent', 'fuel', 'bus', 'train-front',
    'bike', 'dog', 'cat', 'flower', 'gem', 'music', 'film', 'book-open', 'camera', 'phone', 'laptop', 'wifi',
];

// Debounce a rapidly-changing value so the (capped) filter over the whole icon set only runs once the
// user pauses typing, not on every keystroke.
function useDebounced<T>(value: T, ms: number): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const id = setTimeout(() => setDebounced(value), ms);
        return () => clearTimeout(id);
    }, [value, ms]);
    return debounced;
}

export type IconPickerProps = {
    value?: string | null; // the currently selected lucide icon key
    onChange: (key: string | null) => void; // returns the chosen key, or null when cleared
    // Trigger presentation. Defaults suit a "list icon" tile; override for other contexts.
    className?: string; // extra classes on the trigger button
    iconClassName?: string; // size/colour of the icon inside the trigger + placeholder
    ariaLabel?: string; // trigger aria-label (falls back to a generic "choose icon")
    disabled?: boolean;
    allowClear?: boolean; // offer a "no icon" reset inside the sheet (default true)
};

// A generic, reusable icon selector. It shows the current icon on a trigger button; tapping it opens a
// searchable, scrollable grid of the full lucide-react library (the icon set this app already renders
// everywhere). Typing filters by icon name substring (case-insensitive). Picking one returns its key
// via onChange. Rendering reuses <LucideIcon>, the same dynamic renderer used to display stored keys, so
// the key a user picks here is exactly the key that renders back elsewhere.
export function IconPicker({ value, onChange, className, iconClassName, ariaLabel, disabled, allowClear = true }: IconPickerProps) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const debounced = useDebounced(query.trim().toLowerCase(), 120);
    const searchRef = useRef<HTMLInputElement>(null);

    // With no query, show the curated suggestions; otherwise substring-match the whole set, capped. We
    // note when the cap hides matches so the UI can hint that narrowing the search reveals more.
    const { results, truncated } = useMemo(() => {
        if (!debounced) return { results: SUGGESTED, truncated: false };
        const matches: string[] = [];
        for (const name of LUCIDE_ICON_NAMES) {
            if (name.includes(debounced)) {
                matches.push(name);
                if (matches.length >= MAX_RESULTS) break;
            }
        }
        // `break` above stops at the cap; there may be more matches we didn't collect.
        const more = matches.length >= MAX_RESULTS;
        return { results: matches, truncated: more };
    }, [debounced]);

    const pick = (key: string | null) => {
        onChange(key);
        setOpen(false);
        setQuery('');
    };

    return (
        <>
            <button
                type="button"
                disabled={disabled}
                onClick={() => setOpen(true)}
                aria-label={ariaLabel ?? t('common.choose_icon')}
                className={cn(
                    'bg-accent-subtle text-primary hover:border-accent flex size-10 shrink-0 items-center justify-center rounded-lg border border-transparent transition-colors disabled:opacity-50',
                    className,
                )}
            >
                <LucideIcon name={value} className={cn('size-5', iconClassName)} />
            </button>

            <ResponsiveModal
                open={open}
                onOpenChange={setOpen}
                title={t('common.choose_icon')}
                autoFocusRef={searchRef}
                className="sm:max-w-lg"
            >
                <div className="flex flex-col gap-3">
                    {/* Search box: filters the whole lucide library by name (e.g. "car"). */}
                    <div className="relative">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            ref={searchRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('common.search')}
                            className="pl-9"
                            autoComplete="off"
                            autoCorrect="off"
                            spellCheck={false}
                        />
                    </div>

                    {allowClear && value && (
                        <button
                            type="button"
                            onClick={() => pick(null)}
                            className="text-muted-foreground hover:text-foreground flex items-center gap-2 self-start text-sm"
                        >
                            <X className="size-4" /> {t('common.none')}
                        </button>
                    )}

                    {results.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">{t('common.no_matches')}</p>
                    ) : (
                        <div className="grid grid-cols-6 gap-1.5 sm:grid-cols-8">
                            {results.map((name) => {
                                const selected = name === value;
                                return (
                                    <button
                                        key={name}
                                        type="button"
                                        onClick={() => pick(name)}
                                        title={name}
                                        aria-label={name}
                                        aria-pressed={selected}
                                        className={cn(
                                            'flex aspect-square items-center justify-center rounded-lg border transition-colors',
                                            selected
                                                ? 'border-accent-strong bg-accent-subtle text-primary'
                                                : 'text-muted-foreground hover:bg-surface hover:text-foreground border-transparent',
                                        )}
                                    >
                                        <LucideIcon name={name} className="size-5" />
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {truncated && <p className="text-muted-foreground text-center text-xs">{t('common.refine_search')}</p>}
                </div>
            </ResponsiveModal>
        </>
    );
}
