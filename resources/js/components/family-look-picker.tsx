import { Check, Ellipsis } from 'lucide-react';
import { type IconName } from 'lucide-react/dynamic';
import { useState } from 'react';

import { inkOn, monogramOf, SymbolIcon } from '@/components/family-mark';
import { FamilySymbolSheet } from '@/components/family-symbol-sheet';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

// The quick row of symbols next to the monogram; the rest live in the library sheet.
const QUICK: IconName[] = ['house', 'sun', 'sprout', 'mountain'];

// The family's colour row + symbol row (monogram, quick symbols, "more" -> the library sheet), shared by onboarding
// step 1 and Settings > Family name & look so both feel the same. Onboarding is drawn in the fixed brand palette
// (light only, whatever the skin); settings follows the skin and dark mode, hence `brand` picks the class set. The
// settings dots get a hairline so the ink swatch doesn't vanish on a dark page.
export function FamilyLookPicker({
    colors,
    color,
    symbol,
    name,
    onPick,
    brand,
    disabled,
}: {
    colors: string[];
    color: string;
    symbol: string;
    /** For the monogram letter and the sheet's preview. */
    name: string;
    onPick: (key: 'color' | 'symbol', value: string) => void;
    brand?: boolean;
    disabled?: boolean;
}) {
    const t = useT();
    const [sheet, setSheet] = useState(false);
    const c = brand
        ? { ring: 'border-ink', dot: '', tile: 'border-line bg-white', well: 'bg-paper-2' }
        : { ring: 'border-primary', dot: 'border', tile: 'border-border bg-surface-card text-primary', well: 'bg-surface text-primary' };
    // A family can still carry a colour from the older settings palette: put it first in the row so it shows as picked (7 x 44px still fits 390px).
    const palette = colors.includes(color) ? colors : [color, ...colors];
    // The picked symbol joins the quick row when it came from the sheet; none (an emoji-only family) keeps the row as is.
    const quick: IconName[] =
        !symbol || symbol === 'monogram' || QUICK.includes(symbol as IconName) ? QUICK : [symbol as IconName, ...QUICK.slice(0, 3)];

    return (
        <>
            <div className="flex justify-between lg:justify-start lg:gap-3" role="radiogroup" aria-label={t('onboarding.color')}>
                {palette.map((p) => (
                    <button
                        key={p}
                        type="button"
                        role="radio"
                        aria-checked={color === p}
                        aria-label={p}
                        disabled={disabled}
                        onClick={() => onPick('color', p)}
                        className={cn('flex size-11 items-center justify-center rounded-full', color === p ? `${c.ring} border-[1.5px]` : c.dot)}
                        style={{ backgroundColor: p, color: inkOn(p) }}
                    >
                        {color === p && <Check className="size-5" strokeWidth={2.5} />}
                    </button>
                ))}
            </div>
            <div className="flex justify-between lg:justify-start lg:gap-3" role="radiogroup" aria-label={t('onboarding.symbol')}>
                {(['monogram', ...quick] as string[]).map((s) => {
                    const on = symbol === s;
                    return (
                        <button
                            key={s}
                            type="button"
                            role="radio"
                            aria-checked={on}
                            aria-label={s}
                            disabled={disabled}
                            onClick={() => onPick('symbol', s)}
                            className={cn('flex size-11 items-center justify-center rounded-[14px] border', on ? `${c.ring} border-[1.5px]` : c.tile)}
                            style={on ? { backgroundColor: color, color: inkOn(color) } : undefined}
                        >
                            {s === 'monogram' ? (
                                <span className="font-display text-[22px] font-extrabold tracking-[-0.5px]">{monogramOf(name)}</span>
                            ) : (
                                <SymbolIcon name={s as IconName} className="size-[22px]" />
                            )}
                        </button>
                    );
                })}
                <button
                    type="button"
                    aria-label={t('onboarding.symbol_more')}
                    disabled={disabled}
                    onClick={() => setSheet(true)}
                    className={cn('flex size-11 items-center justify-center rounded-[14px]', c.well)}
                >
                    <Ellipsis className="size-[22px]" />
                </button>
            </div>

            <FamilySymbolSheet
                open={sheet}
                onOpenChange={setSheet}
                value={symbol}
                onPick={(s) => onPick('symbol', s)}
                familyName={name}
                color={color}
            />
        </>
    );
}
