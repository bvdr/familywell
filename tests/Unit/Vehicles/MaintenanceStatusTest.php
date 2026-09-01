<?php

use Illuminate\Support\Carbon;
use Modules\Vehicles\MaintenanceStatus;

// The due-logic engine is the heart of the module, so it is tested directly (pure, no DB): each of the
// two triggers, their interaction (EITHER reaching threshold makes it due), and the edge cases —
// never-performed and single-trigger schedules. `today` is fixed so the assertions are deterministic.

const TODAY = '2026-09-01';

function fixedToday(): Carbon
{
    return Carbon::parse(TODAY);
}

it('is good when comfortably before both triggers', function () {
    // Oil change: last 134,200 km / 2026-06-01, every 15,000 km OR 12 months. At 140,000 km today the km
    // trigger has 9,200 km left and the time trigger ~9 months — both well clear.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(3), 140000, fixedToday());

    expect($r['status'])->toBe('good')
        ->and($r['nextDueMileage'])->toBe(149200)
        ->and($r['remainingKm'])->toBe(9200)
        ->and($r['nextDueDate'])->toBe('2027-06-01')
        ->and($r['neverPerformed'])->toBeFalse();
});

it('is due when the mileage trigger is reached but not the time trigger', function () {
    // km trigger exactly reached (149,200 km), time trigger still ~9 months away → overall due.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(3), 149200, fixedToday());

    expect($r['status'])->toBe('due')
        ->and($r['remainingKm'])->toBe(0);
});

it('is due when the time trigger is reached but not the mileage trigger', function () {
    // Time trigger due today (last performed exactly 12 months ago), km trigger still 9,200 km away.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(12), 140000, fixedToday());

    expect($r['status'])->toBe('due')
        ->and($r['remainingDays'])->toBe(0)
        ->and($r['remainingKm'])->toBe(9200);
});

it('is overdue when past both triggers by more than the soon window', function () {
    // 5,800 km past the km due point and a full year past the time due point.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(24), 155000, fixedToday());

    expect($r['status'])->toBe('overdue')
        ->and($r['remainingKm'])->toBe(-5800)
        ->and($r['remainingDays'])->toBeLessThan(0);
});

it('is due soon when within the km window but not yet reached', function () {
    // 700 km short of the 149,200 km due point (<= 1000 km window) → due soon; time still far off.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(2), 148500, fixedToday());

    expect($r['status'])->toBe('due_soon')
        ->and($r['remainingKm'])->toBe(700);
});

it('is due soon when within the time window but not yet reached', function () {
    // Due in 20 days (<= 30 day window); km trigger far off.
    $r = MaintenanceStatus::compute(15000, 12, 134200, fixedToday()->copy()->subMonths(12)->addDays(20), 140000, fixedToday());

    expect($r['status'])->toBe('due_soon')
        ->and($r['remainingDays'])->toBe(20);
});

it('treats a distinct just-past reading as due, not overdue, within the window', function () {
    // 500 km past due (within the 1000 km window) → due (not yet overdue).
    $r = MaintenanceStatus::compute(15000, null, 134200, null, 149700, fixedToday());

    expect($r['status'])->toBe('due')
        ->and($r['remainingKm'])->toBe(-500);
});

it('is due and flagged never-performed when there is no baseline', function () {
    // Schedule exists but was never recorded — surface it as due (needs its first record), no next-due.
    $r = MaintenanceStatus::compute(15000, 12, null, null, 140000, fixedToday());

    expect($r['status'])->toBe('due')
        ->and($r['neverPerformed'])->toBeTrue()
        ->and($r['nextDueMileage'])->toBeNull()
        ->and($r['nextDueDate'])->toBeNull()
        ->and($r['remainingKm'])->toBeNull()
        ->and($r['remainingDays'])->toBeNull();
});

it('handles a km-only schedule (no time trigger)', function () {
    // Brake pads: every 40,000 km, last at 100,000 km, no time interval. At 135,000 km → 5,000 km left.
    $r = MaintenanceStatus::compute(40000, null, 100000, null, 135000, fixedToday());

    expect($r['status'])->toBe('good')
        ->and($r['nextDueMileage'])->toBe(140000)
        ->and($r['remainingKm'])->toBe(5000)
        ->and($r['nextDueDate'])->toBeNull()
        ->and($r['remainingDays'])->toBeNull()
        ->and($r['neverPerformed'])->toBeFalse();
});

it('handles a time-only schedule (no mileage trigger)', function () {
    // Wipers: every 12 months, last 2026-06-01, no km interval. ~9 months left → good.
    $r = MaintenanceStatus::compute(null, 12, null, fixedToday()->copy()->subMonths(3), 140000, fixedToday());

    expect($r['status'])->toBe('good')
        ->and($r['nextDueDate'])->toBe('2027-06-01')
        ->and($r['remainingDays'])->toBeGreaterThan(0)
        ->and($r['nextDueMileage'])->toBeNull()
        ->and($r['remainingKm'])->toBeNull();
});

it('ignores a trigger whose baseline is missing while the other is active', function () {
    // km interval + baseline present (active), months interval set but no last date (inactive) → uses km.
    $r = MaintenanceStatus::compute(15000, 12, 134200, null, 148800, fixedToday());

    expect($r['status'])->toBe('due_soon')   // 400 km left on the only active trigger
        ->and($r['remainingKm'])->toBe(400)
        ->and($r['remainingDays'])->toBeNull();
});

it('rolls statuses up to the coarser vehicle state', function () {
    expect(MaintenanceStatus::toVehicleState('good'))->toBe('good')
        ->and(MaintenanceStatus::toVehicleState('due_soon'))->toBe('service_due')
        ->and(MaintenanceStatus::toVehicleState('due'))->toBe('service_due')
        ->and(MaintenanceStatus::toVehicleState('overdue'))->toBe('overdue');
});

it('picks the more urgent of two statuses', function () {
    expect(MaintenanceStatus::worse('good', 'overdue'))->toBe('overdue')
        ->and(MaintenanceStatus::worse('due_soon', 'due'))->toBe('due')
        ->and(MaintenanceStatus::worse('good', 'good'))->toBe('good');
});
