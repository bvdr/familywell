// New-expense category default: the catch-all "other" wins wherever it exists, regardless of position.
// Plain node:test (see date-groups.test.mjs). Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { defaultCategoryKey } from './categories.ts';

test('defaults a new expense to "other" even when it is last', () => {
    assert.equal(defaultCategoryKey([{ key: 'food' }, { key: 'transport' }, { key: 'other' }]), 'other');
});

test('falls back to the first category when "other" was deleted, and to "other" when there are none', () => {
    assert.equal(defaultCategoryKey([{ key: 'food' }, { key: 'transport' }]), 'food');
    assert.equal(defaultCategoryKey([]), 'other');
});
