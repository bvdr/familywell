// Boundary checks for the Expenses feed date buckets. Plain node:test so it needs no test runner;
// Node >= 22.18 strips the .ts types itself. Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { dateBucket, groupByDateBucket, localToday, setFamilyTimezone } from './date-groups.ts';

// 2026-09-14 is a Monday: the tightest case, since "this week" holds only today + yesterday-as-Sunday.
test('Monday: yesterday wins over last week, the rest of last week is last_week', () => {
    const today = '2026-09-14';
    assert.equal(dateBucket('2026-09-14', today), 'today');
    assert.equal(dateBucket('2026-09-13', today), 'yesterday'); // Sunday, technically last week
    assert.equal(dateBucket('2026-09-12', today), 'last_week'); // Saturday
    assert.equal(dateBucket('2026-09-07', today), 'last_week'); // last Monday
    assert.equal(dateBucket('2026-09-06', today), 'this_month');
    assert.equal(dateBucket('2026-09-01', today), 'this_month');
    assert.equal(dateBucket('2026-08-31', today), '2026-08');
    assert.equal(dateBucket('2025-12-25', today), '2025-12');
});

// 2026-09-17 is a Thursday: this week has room for "earlier this week".
test('mid-week: earlier this week sits between yesterday and last week', () => {
    const today = '2026-09-17';
    assert.equal(dateBucket('2026-09-16', today), 'yesterday');
    assert.equal(dateBucket('2026-09-15', today), 'this_week');
    assert.equal(dateBucket('2026-09-14', today), 'this_week'); // Monday
    assert.equal(dateBucket('2026-09-13', today), 'last_week'); // Sunday
    assert.equal(dateBucket('2026-09-07', today), 'last_week');
    assert.equal(dateBucket('2026-09-06', today), 'this_month');
});

// 2026-09-03 is a Thursday whose "last week" straddles the month boundary (Aug 24 - Aug 30).
test('month boundary: last week can reach into the previous month, older days group by month', () => {
    const today = '2026-09-03';
    assert.equal(dateBucket('2026-09-01', today), 'this_week');
    assert.equal(dateBucket('2026-08-31', today), 'this_week'); // Monday, still this week
    assert.equal(dateBucket('2026-08-30', today), 'last_week');
    assert.equal(dateBucket('2026-08-24', today), 'last_week');
    assert.equal(dateBucket('2026-08-23', today), '2026-08');
});

// 2026-01-01 is a Thursday: year boundary.
test('year boundary: December of last year is its own month', () => {
    assert.equal(dateBucket('2025-12-31', '2026-01-01'), 'yesterday');
    assert.equal(dateBucket('2025-12-28', '2026-01-01'), 'last_week');
    assert.equal(dateBucket('2025-12-20', '2026-01-01'), '2025-12');
});

test('future-dated spends fall under today', () => {
    assert.equal(dateBucket('2026-09-20', '2026-09-14'), 'today');
});

test('groups a newest-first feed into ordered contiguous buckets', () => {
    const feed = ['2026-09-17', '2026-09-17', '2026-09-16', '2026-09-15', '2026-09-10', '2026-09-02', '2026-08-20', '2026-07-01'];
    const groups = groupByDateBucket(feed, (d) => d, '2026-09-17');
    assert.deepEqual(
        groups.map((g) => [g.key, g.items.length]),
        [
            ['today', 2],
            ['yesterday', 1],
            ['this_week', 1],
            ['last_week', 1],
            ['this_month', 1],
            ['2026-08', 1],
            ['2026-07', 1],
        ],
    );
});

test('localToday follows the family zone and steps whole days', () => {
    // Kiritimati (UTC+14) is always exactly one calendar day ahead of Honolulu (UTC-10, no DST).
    setFamilyTimezone('Pacific/Kiritimati');
    const ahead = localToday();
    setFamilyTimezone('Pacific/Honolulu');
    assert.notEqual(localToday(), ahead);
    assert.equal(localToday(1), ahead);
    setFamilyTimezone(null);
});
