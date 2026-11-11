// The icon guess against the shared cases (the PHP twin runs the same file). Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

import { guessIcons } from './icon-guess.ts';

const { cases } = JSON.parse(readFileSync(new URL('../../../tests/fixtures/calendar-icon-guess.json', import.meta.url), 'utf8'));

for (const c of cases) {
    test(`guesses icons for "${c.title}"`, () => {
        assert.deepEqual(guessIcons(c.title), { word: c.word, icons: c.icons });
    });
}
