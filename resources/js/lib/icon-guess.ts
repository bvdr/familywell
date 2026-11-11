// Guesses icons for a calendar event from its title ("Parastas" -> church), so nobody has to pick one. The word table
// is modules/calendar/icon-guess.json, shared with PHP (Modules\Calendar\IconGuess): items, MCP and this picker's
// SUGGESTED row agree. Both are checked against tests/fixtures/calendar-icon-guess.json. Change one, change both.
import data from '../../../modules/calendar/icon-guess.json' with { type: 'json' };

export const MAX_GUESSES = 5;

// Lower-case ASCII of one word. NFD drops accents (ă ș é ü å ...); it leaves ß ø æ œ ł alone, so those are mapped by
// hand to what PHP's Str::ascii gives them.
const EXTRA: Record<string, string> = { ß: 'ss', ø: 'o', æ: 'ae', œ: 'oe', ł: 'l', đ: 'd' };
const foldWord = (w: string) =>
    w
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[ßøæœłđ]/g, (c) => EXTRA[c]);

// Split once into exact ("word$") and prefix words; the file is small and this runs on every title keystroke.
const GROUPS = data.groups.map((g) => ({
    exact: new Set(g.words.filter((w) => w.endsWith('$')).map((w) => w.slice(0, -1))),
    prefix: g.words.filter((w) => !w.endsWith('$')),
    icons: g.icons,
}));

/** Up to 5 icons for a title, best first, and the title word that triggered the first match ("Suggested for PARASTAS"). */
export function guessIcons(title: string): { word: string | null; icons: string[] } {
    const icons: string[] = [];
    let word: string | null = null;
    for (const token of title.split(/[^\p{L}\p{N}]+/u)) {
        if (!token) continue;
        const folded = foldWord(token);
        // First matching group per word, like IconGuess::guess().
        const g = GROUPS.find((x) => x.exact.has(folded) || x.prefix.some((p) => folded.startsWith(p)));
        if (!g) continue;
        word ??= token;
        for (const i of g.icons) if (!icons.includes(i)) icons.push(i);
        if (icons.length >= MAX_GUESSES) break;
    }
    return { word, icons: icons.slice(0, MAX_GUESSES) };
}
