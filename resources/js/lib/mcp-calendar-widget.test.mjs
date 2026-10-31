// The calendar_list_entries MCP widget (resources/mcp-apps/calendar.js) buckets items per day. Run: `npm run test:js`.
// Pinned to a zone with a DST change inside the range: stepping +24h from local midnight used to show
// 25 Oct 2026 twice and drop 27 Oct. Each test file runs in its own process, so setting TZ here is safe.
// (Imports are hoisted; node re-reads TZ on change, and no Date is made before this line runs.)
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import process from 'node:process';
import { test } from 'node:test';

process.env.TZ = 'Europe/Bucharest';

// Run the widget script against a stub FW and return the day headers it renders.
function renderDays(data) {
    let out;
    const h = (tag, attrs, ...kids) => ({ tag, attrs, kids: kids.flat(Infinity) });
    globalThis.FW = { h, day: (s) => s, head: () => null, open() {}, start: (fn) => (out = fn(data)) };
    new Function(readFileSync(new URL('../../mcp-apps/calendar.js', import.meta.url), 'utf8'))();
    return out.kids.filter((c) => c?.attrs?.class === 'card').map((c) => c.kids[0].kids[0]);
}

test('a trip across the autumn DST change covers each day once', () => {
    const days = renderDays({ from: '2026-10-01', to: '2026-10-31', items: [{ title: 'Trip', start: '2026-10-23', end: '2026-10-27', people: [] }] });
    assert.deepEqual(days, ['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27']);
});

test('a trip across the spring DST change covers each day once', () => {
    const days = renderDays({ from: '2026-03-01', to: '2026-03-31', items: [{ title: 'Trip', start: '2026-03-27', end: '2026-03-31', people: [] }] });
    assert.deepEqual(days, ['2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31']);
});
