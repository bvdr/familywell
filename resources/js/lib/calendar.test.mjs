// resources/js/lib/calendar.test.mjs — Run: `npm run test:js`.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { addDays, clip, daysBetween, filterItems, isOverdue, itemsOn, layoutWeek, shiftDate, timelineRows, weeksOf } from './calendar.ts';

const item = (over) => ({
    source: 'calendar',
    kind: 'event',
    id: 1,
    title: 'X',
    start: '2026-07-17',
    end: '2026-07-17',
    startTime: null,
    endTime: null,
    href: '',
    icon: 'calendar',
    color: null,
    people: [],
    done: false,
    editable: true,
    ...over,
});

test('date math stays on local days across DST', () => {
    assert.equal(addDays('2026-10-24', 2), '2026-10-26'); // EU DST ends 25 Oct
    assert.equal(daysBetween('2026-03-28', '2026-03-30'), 2);
});

test('weeksOf splits a 42-day grid into 6 weeks', () => {
    const w = weeksOf('2026-06-29', '2026-08-09');
    assert.equal(w.length, 6);
    assert.deepEqual([w[0][0], w[5][6]], ['2026-06-29', '2026-08-09']);
});

test('itemsOn includes multi-day items covering the day', () => {
    const trip = item({ start: '2026-07-17', end: '2026-07-25' });
    assert.equal(itemsOn([trip], '2026-07-20').length, 1);
    assert.equal(itemsOn([trip], '2026-07-26').length, 0);
});

test('layoutWeek clips bars to the week and stacks overlaps in lanes', () => {
    const week = ['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17', '2026-07-18', '2026-07-19'];
    const trip = item({ id: 1, start: '2026-07-17', end: '2026-07-25' });
    const dentist = item({ id: 2, start: '2026-07-17', end: '2026-07-17' });
    const camp = item({ id: 3, start: '2026-07-10', end: '2026-07-14' });
    const out = layoutWeek([dentist, trip, camp], week);
    const by = Object.fromEntries(out.map((o) => [o.item.id, o]));
    assert.deepEqual([by[1].col, by[1].span], [4, 3]); // Fri..Sun, clipped
    assert.deepEqual([by[3].col, by[3].span], [0, 2]); // started last week
    assert.notEqual(by[1].lane, by[2].lane); // same Friday, different lanes
});

test('clip places a timeline bar inside the window', () => {
    assert.deepEqual(clip(item({ start: '2026-07-10', end: '2026-07-14' }), '2026-07-12', '2026-07-31'), { offset: 0, span: 3 });
    assert.deepEqual(clip(item({ start: '2026-07-17', end: '2026-07-25' }), '2026-07-12', '2026-07-31'), { offset: 5, span: 9 });
    assert.equal(clip(item({ start: '2026-08-02', end: '2026-08-03' }), '2026-07-12', '2026-07-31'), null);
});

test('filterItems keeps items with a selected person or source; empty filter keeps all', () => {
    const a = item({ id: 1, people: [{ type: 'user', id: 1, name: 'B' }] });
    const b = item({ id: 2, source: 'tasks' });
    assert.equal(filterItems([a, b], { people: [], sources: [] }).length, 2);
    assert.deepEqual(
        filterItems([a, b], { people: ['user:1'], sources: [] }).map((i) => i.id),
        [1],
    );
    assert.deepEqual(
        filterItems([a, b], { people: [], sources: ['tasks'] }).map((i) => i.id),
        [2],
    );
});

test('shiftDate moves by month or week', () => {
    assert.equal(shiftDate('month', 'month', '2026-01-31', 1), '2026-02-01');
    assert.equal(shiftDate('timeline', 'week', '2026-07-15', -1), '2026-07-08');
    assert.equal(shiftDate('timeline', 'month', '2026-07-15', 1), '2026-08-01');
});

test('timelineRows keeps in-window items in start order with clipped geometry', () => {
    const a = item({ id: 1, title: 'Tabăra', start: '2026-07-26', end: '2026-08-01' });
    const b = item({ id: 2, title: 'Grecia', start: '2026-07-17', end: '2026-07-25' });
    const c = item({ id: 3, title: 'Paște', start: '2026-04-12', end: '2026-04-12' });
    const rows = timelineRows([a, b, c], '2026-07-12', '2026-07-31');
    assert.deepEqual(
        rows.map((r) => [r.item.title, r.bars.map((x) => [x.offset, x.span])]),
        [
            ['Grecia', [[5, 9]]],
            ['Tabăra', [[14, 6]]],
        ],
    );
});

test('timelineRows puts every occurrence of a repeating item on one row', () => {
    const swim = (start) => item({ id: 7, title: 'Swim class', start, end: start });
    const rows = timelineRows([swim('2026-07-22'), swim('2026-07-08'), swim('2026-07-15'), swim('2026-08-05')], '2026-07-01', '2026-07-31');
    assert.equal(rows.length, 1);
    assert.deepEqual(
        rows[0].bars.map((x) => x.item.start),
        ['2026-07-08', '2026-07-15', '2026-07-22'],
    );
    assert.equal(rows[0].item.start, '2026-07-08');
});

test('timelineRows breaks a same-start tie by title', () => {
    const rows = timelineRows(
        [item({ id: 1, title: 'Zoo' }), item({ id: 2, title: 'Art' }), item({ id: 3, kind: 'task', title: 'Bake' })],
        '2026-07-01',
        '2026-07-31',
    );
    assert.deepEqual(
        rows.map((r) => r.item.title),
        ['Art', 'Bake', 'Zoo'],
    );
});

test('isOverdue: open to-dos past their date; never done ones, events or birthdays', () => {
    const today = '2026-10-19';
    assert.equal(isOverdue(item({ kind: 'task', end: '2026-10-18' }), today), true);
    assert.equal(isOverdue(item({ kind: 'task', end: '2026-10-19' }), today), false); // due today isn't late yet
    assert.equal(isOverdue(item({ kind: 'task', end: '2026-10-18', done: true }), today), false);
    assert.equal(isOverdue(item({ kind: 'vehicle_maintenance', end: '2026-10-01' }), today), true);
    assert.equal(isOverdue(item({ kind: 'event', end: '2026-10-01' }), today), false);
    assert.equal(isOverdue(item({ kind: 'birthday', end: '2026-10-01' }), today), false);
});

test('shiftDate: the week view steps a week, the year timeline a year', () => {
    assert.equal(shiftDate('week', 'month', '2026-10-19', 1), '2026-10-26');
    assert.equal(shiftDate('timeline', 'year', '2026-10-19', -1), '2025-01-01');
    assert.equal(shiftDate('timeline', 'year', '2026-10-19', 1), '2027-01-01');
});
