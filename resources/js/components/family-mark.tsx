import { DynamicIcon, type IconName } from 'lucide-react/dynamic';

import { isLucideIconName } from '@/components/lucide-icon';
import { cn } from '@/lib/utils';

// A symbol icon, lazy-loaded per icon like LucideIcon, but with an empty same-size box while it loads (not
// LucideIcon's Wallet stand-in, which would flash in the symbol grids).
export function SymbolIcon({ name, className, strokeWidth = 2.25 }: { name: IconName; className?: string; strokeWidth?: number }) {
    return (
        <DynamicIcon
            name={name}
            className={className}
            strokeWidth={strokeWidth}
            fallback={() => <span className={cn('inline-block', className)} />}
        />
    );
}

// Words our own name suggestions put around the surname ("The Riveras", "Casa Rivera", "Familien Berg"), in
// every language we ship. The monogram skips them, so it's the R of the Riveras, not the T of "The".
const FILLER = new Set(
    'the team casa hq los las les la le el die der das de familia família familie familien familjen famille huize haus hos hjemme equipo equipe echipa'.split(
        ' ',
    ),
);

/** The family's monogram letter: the first word that isn't one of the FILLER words, else the first word. */
export function monogramOf(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const word = words.find((w) => !FILLER.has(w.toLocaleLowerCase())) ?? words[0] ?? '·';
    return word[0].toLocaleUpperCase();
}

type Props = {
    name: string;
    color: string;
    symbol?: string | null;
    emoji?: string | null;
    className?: string;
    iconClassName?: string;
};

// Ink or white on the family colour, whichever reads: the onboarding palette has light colours (sun, green,
// lilac) and ink itself. Relative luminance from the hex, the WCAG formula without gamma (close enough here).
export function inkOn(hex: string): string {
    const n = parseInt(hex.replace('#', ''), 16);
    const lum = (0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    return lum < 0.45 ? '#FFFFFF' : '#121100';
}

// The family's mark: its symbol (a lucide icon, or 'monogram' = the name's first letter) on its colour, else the
// emoji every family had before symbols existed (and still has: an unknown symbol key falls back to it too).
export function FamilyMark({ name, color, symbol, emoji, className, iconClassName }: Props) {
    const fg = inkOn(color);
    return (
        <span className={cn('flex shrink-0 items-center justify-center', className)} style={{ backgroundColor: color, color: fg }} aria-hidden>
            {symbol === 'monogram' ? (
                <span className="font-display leading-none font-extrabold">{monogramOf(name)}</span>
            ) : isLucideIconName(symbol) ? (
                <SymbolIcon name={symbol} className={cn('size-1/2', iconClassName)} />
            ) : (
                emoji
            )}
        </span>
    );
}
