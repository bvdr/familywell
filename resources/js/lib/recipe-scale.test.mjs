// The recipe calculator against the shared cases (the PHP twin runs the same file). Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { factorFor, formatQty, scaleIngredient } from './recipe-scale.js';

const cases = JSON.parse(readFileSync(new URL('../../../tests/fixtures/recipe-scale.json', import.meta.url), 'utf8'));

test('scale factor from servings, yield or an ingredient you have', () => {
    for (const c of cases.factor) {
        const f = factorFor(cases.recipe, c.target);
        assert.equal(f === null ? null : Math.round(f * 10000) / 10000, c.expect, JSON.stringify(c.target));
    }
});

test('scaled ingredients are rounded and tidied', () => {
    for (const c of cases.scale) assert.deepEqual(scaleIngredient(c.ing, c.factor), c.expect, JSON.stringify(c));
});

test('spoons read as fractions', () => {
    assert.equal(formatQty(1.5, 'tbsp'), '1½');
    assert.equal(formatQty(0.75, 'tsp'), '¾');
    assert.equal(formatQty(1250, 'g', 'en-US'), '1,250');
});
