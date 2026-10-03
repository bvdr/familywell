<?php

use Modules\Calendar\Occurrences;

// Pure repeat expansion: (start, end, repeat, interval, until, from, to) -> occurrence start dates.
it('returns a one-off event only when it overlaps the range', function () {
    expect(Occurrences::starts('2026-07-17', '2026-07-25', 'none', 1, null, '2026-07-20', '2026-08-31'))->toBe(['2026-07-17'])
        ->and(Occurrences::starts('2026-07-17', '2026-07-25', 'none', 1, null, '2026-07-26', '2026-08-31'))->toBe([])
        ->and(Occurrences::starts('2026-07-17', '2026-07-17', 'none', 1, null, '2026-07-01', '2026-07-17'))->toBe(['2026-07-17']);
});

it('repeats daily and weekly with an interval and stops at until', function () {
    expect(Occurrences::starts('2026-07-01', '2026-07-01', 'daily', 2, '2026-07-07', '2026-07-01', '2026-07-31'))
        ->toBe(['2026-07-01', '2026-07-03', '2026-07-05', '2026-07-07'])
        ->and(Occurrences::starts('2026-07-06', '2026-07-06', 'weekly', 1, null, '2026-07-10', '2026-07-31'))
        ->toBe(['2026-07-13', '2026-07-20', '2026-07-27']);
});

it('includes a repeating multi-day block that started before the range', function () {
    // Every week Fri-Sun; viewing from Saturday catches the block that began Friday.
    expect(Occurrences::starts('2026-07-03', '2026-07-05', 'weekly', 1, null, '2026-07-11', '2026-07-12'))->toBe(['2026-07-10']);
});

it('skips months without the day instead of drifting', function () {
    expect(Occurrences::starts('2026-01-31', '2026-01-31', 'monthly', 1, null, '2026-01-01', '2026-06-30'))
        ->toBe(['2026-01-31', '2026-03-31', '2026-05-31']);
});

it('puts Feb 29 only in leap years', function () {
    expect(Occurrences::starts('2024-02-29', '2024-02-29', 'yearly', 1, null, '2025-01-01', '2028-12-31'))->toBe(['2028-02-29']);
});

it('repeats yearly every N years', function () {
    expect(Occurrences::starts('2020-04-12', '2020-04-12', 'yearly', 2, null, '2026-01-01', '2026-12-31'))->toBe(['2026-04-12'])
        ->and(Occurrences::starts('2020-04-12', '2020-04-12', 'yearly', 2, null, '2027-01-01', '2027-12-31'))->toBe([]);
});

it('never starts before the event itself', function () {
    expect(Occurrences::starts('2026-07-15', '2026-07-15', 'daily', 1, null, '2026-07-01', '2026-07-16'))->toBe(['2026-07-15', '2026-07-16']);
});
