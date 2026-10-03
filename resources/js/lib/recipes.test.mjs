// Pasted-ingredient parsing for the recipe editor. Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { parseIngredients } from './recipes.ts';

test('parses amounts, known units, notes and sections', () => {
    const rows = parseIngredients('Dough:\n500 g flour\n- 2 eggs (room temperature)\n1½ tbsp sugar\n1/2 tsp salt\nsalt, to taste\n250 grame unt');
    assert.deepEqual(
        rows.map((r) => [r.group, r.qty, r.unit, r.name, r.note]),
        [
            ['Dough', 500, 'g', 'flour', null],
            ['Dough', 2, null, 'eggs', 'room temperature'],
            ['Dough', 1.5, 'tbsp', 'sugar', null],
            ['Dough', 0.5, 'tsp', 'salt', null],
            ['Dough', null, null, 'salt', null],
            ['Dough', 250, 'grame', 'unt', null],
        ],
    );
});
