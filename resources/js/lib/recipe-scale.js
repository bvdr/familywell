// Recipe calculator: one scale factor, from servings, from how much you want to make (yield), or from
// how much of one ingredient you have ("I only have 400 g of flour"), then every ingredient scaled and
// tidied. Plain JS with no imports on purpose: the web page imports it, the MCP widget inlines this
// same file (App\Mcp\Apps\Widget strips the `export`s), and node:test runs it. The PHP twin
// (Modules\Recipes\RecipeScale, for the MCP tools) must agree: both are checked against
// tests/fixtures/recipe-scale.json.

// Units we can convert between, as [dimension, size in the base unit (g / ml)]. Anything else (cups,
// pieces, pinches, cans) still scales, it just never converts. Spoons are metric (5 / 15 ml).
const RS_UNITS = {
    mg: ['mass', 0.001],
    g: ['mass', 1],
    kg: ['mass', 1000],
    ml: ['volume', 1],
    cl: ['volume', 10],
    dl: ['volume', 100],
    l: ['volume', 1000],
    tsp: ['volume', 5],
    tbsp: ['volume', 15],
};

// How people actually type units (English + Romanian), mapped to the keys above.
const RS_ALIASES = {
    gr: 'g',
    gram: 'g',
    grams: 'g',
    grame: 'g',
    kilogram: 'kg',
    kilograms: 'kg',
    kilograme: 'kg',
    kilo: 'kg',
    milliliter: 'ml',
    millilitre: 'ml',
    milliliters: 'ml',
    millilitres: 'ml',
    mililitri: 'ml',
    liter: 'l',
    litre: 'l',
    liters: 'l',
    litres: 'l',
    litru: 'l',
    litri: 'l',
    teaspoon: 'tsp',
    teaspoons: 'tsp',
    linguriță: 'tsp',
    lingurita: 'tsp',
    lingurițe: 'tsp',
    lingurite: 'tsp',
    tablespoon: 'tbsp',
    tablespoons: 'tbsp',
    lingură: 'tbsp',
    lingura: 'tbsp',
    linguri: 'tbsp',
    cups: 'cup',
    cană: 'cup',
    cana: 'cup',
    căni: 'cup',
    piece: 'pcs',
    pieces: 'pcs',
    pc: 'pcs',
    buc: 'pcs',
    bucăți: 'pcs',
    bucati: 'pcs',
    bucată: 'pcs',
    bucata: 'pcs',
};

// Spoon-like measures read best in quarters (¾ tsp), not decimals.
const RS_QUARTERS = ['tsp', 'tbsp', 'cup'];

/** Units offered in the editor (free text is allowed too). */
export const RECIPE_UNITS = ['g', 'kg', 'ml', 'l', 'tsp', 'tbsp', 'cup', 'pcs', 'pinch', 'clove', 'slice', 'can', 'pack'];

/** @param {string|null|undefined} unit */
export function normUnit(unit) {
    const k = String(unit ?? '')
        .trim()
        .toLowerCase()
        .replace(/\.$/, '');
    return RS_ALIASES[k] ?? k;
}

/** qty in `from` expressed in `to`, or null when they don't convert (different dimension / unknown). */
export function convertUnit(qty, from, to) {
    const f = normUnit(from);
    const t = normUnit(to);
    if (f === t) return qty;
    const a = RS_UNITS[f];
    const b = RS_UNITS[t];
    return a && b && a[0] === b[0] ? (qty * a[1]) / b[1] : null;
}

/**
 * The scale factor for a target, or null when it can't be computed.
 * recipe: {servings, yieldAmount, yieldUnit, ingredients: [{qty, unit}]}
 * target: {type: 'servings', value} | {type: 'yield', amount, unit} | {type: 'ingredient', index, amount, unit}
 */
export function factorFor(recipe, target) {
    let f = null;
    if (target.type === 'servings') {
        f = recipe.servings ? target.value / recipe.servings : null;
    } else if (target.type === 'yield') {
        const amount = recipe.yieldAmount ? convertUnit(target.amount, target.unit ?? recipe.yieldUnit, recipe.yieldUnit) : null;
        f = amount === null ? null : amount / recipe.yieldAmount;
    } else if (target.type === 'ingredient') {
        const ing = recipe.ingredients[target.index];
        const amount = ing && ing.qty ? convertUnit(target.amount, target.unit ?? ing.unit, ing.unit) : null;
        f = amount === null ? null : amount / ing.qty;
    }
    return f !== null && Number.isFinite(f) && f > 0 ? f : null;
}

const rsRound = (q, decimals) => Math.floor(q * 10 ** decimals + 0.5) / 10 ** decimals; // same in PHP

/** A scaled amount, rounded the way a cook reads it. */
export function roundQty(q, unit) {
    const u = normUnit(unit);
    if (RS_QUARTERS.includes(u)) return Math.max(0.25, rsRound(q * 4, 0) / 4);
    if (u === 'kg' || u === 'l') return rsRound(q, 2);
    if (u === 'g' || u === 'ml' || u === 'mg' || u === 'cl' || u === 'dl') return q >= 10 ? rsRound(q, 0) : rsRound(q, 1);
    return q >= 10 ? rsRound(q, 0) : Math.max(0.5, rsRound(q * 2, 0) / 2); // pieces, pinches...: halves
}

/**
 * Scale one ingredient. "To taste" (no qty) and non-scalable ones come back unchanged. Grams roll up to
 * kilograms at 1000 (and back down below 1 kg), same for ml / l; otherwise the cook's own unit spelling
 * is kept. @returns {{qty: number|null, unit: string|null}}
 */
export function scaleIngredient(ing, factor) {
    if (ing.qty === null || ing.qty === undefined || ing.qty === '' || ing.scalable === false || factor === 1) {
        return { qty: ing.qty === '' || ing.qty === undefined ? null : ing.qty, unit: ing.unit ?? null };
    }
    let q = Number(ing.qty) * factor;
    const orig = normUnit(ing.unit);
    let u = orig;
    if (u === 'g' && q >= 1000) [q, u] = [q / 1000, 'kg'];
    else if (u === 'kg' && q < 1) [q, u] = [q * 1000, 'g'];
    else if (u === 'ml' && q >= 1000) [q, u] = [q / 1000, 'l'];
    else if (u === 'l' && q < 1) [q, u] = [q * 1000, 'ml'];
    return { qty: roundQty(q, u), unit: u === orig ? (ing.unit ?? null) : u };
}

/** Display a quantity: quarters as fractions for spoons/cups ("1½"), otherwise a localized number. */
export function formatQty(qty, unit, locale) {
    if (qty === null || qty === undefined) return '';
    if (RS_QUARTERS.includes(normUnit(unit))) {
        const whole = Math.floor(qty);
        const frac = { 0.25: '¼', 0.5: '½', 0.75: '¾' }[Math.round((qty - whole) * 4) / 4] ?? '';
        return (whole ? String(whole) : '') + frac || '0';
    }
    return Number(qty).toLocaleString(locale, { maximumFractionDigits: 2 });
}
