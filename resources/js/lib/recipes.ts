import { RECIPE_UNITS, normUnit } from './recipe-scale.js';

// A recipe as the pages get it (Recipe::toPayload). Ingredients/steps are the stored JSON as-is.
export type Ingredient = {
    group: string | null;
    name: string;
    qty: number | null; // null = to taste
    unit: string | null;
    note: string | null;
    scalable: boolean;
};

export type Recipe = {
    id: number;
    title: string;
    description: string | null;
    photoUrl: string | null;
    thumbUrl: string | null;
    servings: number | null;
    yieldAmount: number | null;
    yieldUnit: string | null;
    prepMinutes: number | null;
    cookMinutes: number | null;
    ingredients: Ingredient[];
    steps: string[];
    tags: string[];
    sourceUrl: string | null;
    notes: string | null;
    visibility: string;
    updatedAt: string | null;
};

export type ScaleTarget =
    | { type: 'servings'; value: number }
    | { type: 'yield'; amount: number; unit: string | null }
    | { type: 'ingredient'; index: number; amount: number; unit: string | null };

/** "1 h 15 min" / "40 min". */
export function minutesText(m: number | null): string | null {
    if (!m) return null;
    return m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`;
}

/**
 * Parse pasted ingredient lines ("500 g flour", "2 eggs", "salt, to taste", "1½ tbsp sugar") into rows.
 * A word after the number counts as the unit only when it is one we know (so "2 eggs" keeps "eggs" as the
 * name). Fractions: ½ ¼ ¾ and 1/2. A trailing "(...)" or ", ..." becomes the note. Lines ending in ":"
 * start a section ("Dough:").
 */
export function parseIngredients(text: string): Ingredient[] {
    const out: Ingredient[] = [];
    let group: string | null = null;
    const frac: Record<string, number> = { '½': 0.5, '¼': 0.25, '¾': 0.75 };
    for (const raw of text.split('\n')) {
        let line = raw.replace(/^[\s\-*•]+/, '').trim();
        if (!line) continue;
        if (line.endsWith(':')) {
            group = line.slice(0, -1).trim() || null;
            continue;
        }
        let qty: number | null = null;
        const m = line.match(/^(\d+(?:[.,]\d+)?)?\s*([½¼¾])?(?:\s*\/\s*(\d+))?\s*/);
        if (m && (m[1] || m[2])) {
            const whole = m[1] ? parseFloat(m[1].replace(',', '.')) : 0;
            qty = m[3] && m[1] ? whole / parseFloat(m[3]) : whole + (m[2] ? frac[m[2]] : 0);
            line = line.slice(m[0].length);
        }
        let unit: string | null = null;
        const u = line.match(/^(\S+)\s+/);
        if (qty !== null && u && (RECIPE_UNITS.includes(normUnit(u[1])) || normUnit(u[1]) !== u[1].toLowerCase().replace(/\.$/, ''))) {
            unit = u[1].replace(/\.$/, '');
            line = line.slice(u[0].length);
        }
        let note: string | null = null;
        const n = line.match(/^(.*?)(?:\s*\((.+)\)|,\s*(.+))$/);
        if (n) {
            line = n[1];
            note = (n[2] ?? n[3] ?? '').trim() || null;
        }
        // "salt, to taste": no amount already reads as to taste, so don't repeat it as a note.
        if (qty === null && note && /^(to taste|după gust|dupa gust)$/i.test(note)) note = null;
        const name = line.replace(/^(of|de)\s+/i, '').trim();
        if (name) out.push({ group, name, qty, unit, note, scalable: true });
    }
    return out;
}
