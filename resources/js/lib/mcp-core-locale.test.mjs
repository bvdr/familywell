// Shared MCP widget runtime (resources/mcp-apps/core.js): host locales arrive in the host's own spelling.
// ChatGPT sent "en_RO", Intl threw "invalid language tag" and every widget failed to draw. Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

function loadFW() {
    globalThis.document = { getElementById: () => null };
    return new Function(readFileSync(new URL('../../mcp-apps/core.js', import.meta.url), 'utf8') + '\nreturn FW;')();
}

test('turns host locale spellings into valid BCP 47 tags', () => {
    const { toLocale } = loadFW();
    assert.equal(toLocale('en_RO'), 'en-RO');
    assert.equal(toLocale('ro-RO'), 'ro-RO');
    assert.equal(toLocale('ro'), 'ro');
});

test('falls back to the browser default for missing or nonsense locales', () => {
    const { toLocale } = loadFW();
    assert.equal(toLocale(undefined), undefined);
    assert.equal(toLocale(''), undefined);
    assert.equal(toLocale('not a locale!'), undefined);
});
