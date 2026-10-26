import { Check, Search } from 'lucide-react';
import { type IconName } from 'lucide-react/dynamic';
import { useEffect, useMemo, useState } from 'react';

import { SymbolIcon } from '@/components/family-mark';
import { isLucideIconName, LUCIDE_ICON_NAMES } from '@/components/lucide-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The symbol library (design 01b), grouped by what families care about. Each entry: lucide key + extra search
// words (English, like the search placeholder) beyond the words already in the key.
export const SYMBOL_GROUPS: { id: 'home' | 'nature' | 'pets' | 'hobbies'; symbols: [IconName, string][] }[] = [
    {
        id: 'home',
        symbols: [
            ['house', 'home'],
            ['heart', 'love home'] /* design's house-heart isn't in our lucide version */,
            ['door-open', 'welcome'],
            ['sofa', 'couch living'],
            ['armchair', 'chair'],
            ['key-round', 'keys'],
            ['lamp', 'light'],
            ['bed-double', 'sleep bedroom'],
            ['flame', 'fire fireplace'],
            ['mailbox', 'mail post'],
            ['fence', 'garden yard'],
            ['utensils', 'food kitchen dinner'],
        ],
    },
    {
        id: 'nature',
        symbols: [
            ['sprout', 'plant grow'],
            ['leaf', 'green'],
            ['trees', 'forest woods'],
            ['flower-2', 'bloom'],
            ['mountain', 'hike peak'],
            ['waves', 'sea ocean beach water'],
            ['sun', 'sunny summer'],
            ['moon', 'night'],
            ['cloud-sun', 'weather'],
            ['snowflake', 'winter snow ski'],
            ['clover', 'luck'],
            ['cherry', 'fruit'],
        ],
    },
    {
        id: 'pets',
        symbols: [
            ['paw-print', 'pet animal'],
            ['dog', 'puppy'],
            ['cat', 'kitten'],
            ['bird', 'parrot'],
            ['fish', 'aquarium'],
            ['rabbit', 'bunny'],
            ['turtle', 'tortoise'],
            ['squirrel', 'animal'],
            ['bone', 'dog'],
            ['egg', 'chicken hen'],
            ['feather', 'bird'],
            ['bug', 'insect'],
        ],
    },
    {
        id: 'hobbies',
        symbols: [
            ['music', 'song'],
            ['guitar', 'band rock'],
            ['bike', 'cycling bicycle'],
            ['tent', 'camping'],
            ['sailboat', 'boat sailing beach'],
            ['book-open', 'reading books'],
            ['gamepad-2', 'games gaming'],
            ['coffee', 'cafe tea'],
            ['palette', 'art painting'],
            ['camera', 'photo'],
            ['volleyball', 'sport ball beach'],
            ['plane', 'travel trip holiday'],
        ],
    },
];

// Search past the curated set into the whole lucide library, so "pizza" or "baby" still finds something.
// Capped: each cell lazy-loads its icon (see IconPicker's MAX_RESULTS reasoning).
const MAX_EXTRA = 24;

export function FamilySymbolSheet({
    open,
    onOpenChange,
    value,
    onPick,
    familyName,
    color,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    value: string;
    onPick: (symbol: string) => void;
    familyName: string;
    color: string;
}) {
    const t = useT();
    const [query, setQuery] = useState('');
    const [group, setGroup] = useState<'all' | (typeof SYMBOL_GROUPS)[number]['id']>('all');
    // Picked inside the sheet; only applied on "Use this symbol", so closing it changes nothing.
    const [draft, setDraft] = useState(value);
    useEffect(() => {
        if (open) setDraft(value);
    }, [open, value]);

    const q = query.trim().toLowerCase();
    const groups = useMemo(
        () =>
            SYMBOL_GROUPS.filter((g) => group === 'all' || g.id === group)
                .map((g) => ({ ...g, symbols: g.symbols.filter(([name, words]) => !q || `${name} ${words}`.includes(q)) }))
                .filter((g) => g.symbols.length),
        [group, q],
    );
    const extra = useMemo(() => {
        if (q.length < 2) return [];
        const curated = new Set(SYMBOL_GROUPS.flatMap((g) => g.symbols.map(([n]) => n)));
        return LUCIDE_ICON_NAMES.filter((n) => n.includes(q) && !curated.has(n as IconName)).slice(0, MAX_EXTRA) as IconName[];
    }, [q]);

    const cell = (name: IconName) => (
        <button
            key={name}
            type="button"
            aria-label={name}
            aria-pressed={draft === name}
            onClick={() => setDraft(name)}
            className={cn(
                'flex aspect-square items-center justify-center rounded-[15px] border',
                draft === name ? 'border-ink border-[1.5px]' : 'border-line bg-white',
            )}
            style={draft === name ? { backgroundColor: color } : undefined}
        >
            <SymbolIcon name={name} className="size-[22px]" />
        </button>
    );

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('onboarding.symbol_title')}
            description={familyName}
            className="bg-paper text-ink [&_h2]:font-display sm:max-w-[760px] [&_h2]:text-[22px] [&_h2]:font-bold [&_h2]:tracking-[-0.5px]"
            footer={
                <div className="flex items-center justify-between gap-4">
                <p className="text-stone hidden text-[13px] md:block">{t('onboarding.symbol_hint')}</p>
                <Button
                    variant="sun"
                    size="cta"
                    className="w-full md:w-auto md:min-w-[240px]"
                    disabled={!isLucideIconName(draft)}
                    onClick={() => {
                        onPick(draft);
                        onOpenChange(false);
                    }}
                >
                    {t('onboarding.symbol_use')}
                    <Check />
                </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-3.5">
                <div className="flex flex-col gap-3.5 md:flex-row md:items-center">
                <div className="relative md:flex-1">
                    <Search className="text-stone pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('onboarding.symbol_search')}
                        className="border-line h-[41px] rounded-[14px] bg-white pl-10 text-sm"
                    />
                </div>
                <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1" data-scroll-x>
                    {(['all', ...SYMBOL_GROUPS.map((g) => g.id)] as const).map((id) => (
                        <button
                            key={id}
                            type="button"
                            onClick={() => setGroup(id)}
                            className={cn(
                                'shrink-0 rounded-full px-[11px] py-1.5 text-xs font-semibold',
                                group === id ? 'bg-ink text-white' : 'bg-paper-2 text-ink-2',
                            )}
                        >
                            {t(`onboarding.symbol_group_${id}`)}
                        </button>
                    ))}
                </div>
                {groups.map((g) => (
                    <section key={g.id} className="flex flex-col gap-2">
                        <h3 className="text-stone font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">
                            {t(`onboarding.symbol_group_${g.id}`)}
                        </h3>
                        <div className="grid grid-cols-6 gap-2.5 md:grid-cols-12 md:gap-3">{g.symbols.map(([name]) => cell(name))}</div>
                    </section>
                ))}
                {extra.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h3 className="text-stone font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">
                            {t('onboarding.symbol_group_more')}
                        </h3>
                        <div className="grid grid-cols-6 gap-2.5 md:grid-cols-12 md:gap-3">{extra.map(cell)}</div>
                    </section>
                )}
                {groups.length === 0 && extra.length === 0 && <p className="text-stone py-6 text-center text-sm">{t('onboarding.symbol_none')}</p>}
            </div>
        </ResponsiveModal>
    );
}
