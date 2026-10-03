<?php

use App\Support\Dashboard\DashboardData;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

it('puts an ongoing trip on Today once with day N of M, and a later one in This Week', function () {
    Carbon::setTestNow('2026-07-20 10:00'); // Monday
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    trip($users[0]); // Grecia 17-25 Jul
    trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-07-23', 'end_date' => '2026-07-23', 'start_time' => '09:00', 'end_time' => '10:00']);

    $d = DashboardData::build();
    $today = collect($d['today'])->where('kind', 'event')->values();
    $week = collect($d['thisWeek'])->where('kind', 'event')->values();
    expect($today->pluck('title')->all())->toBe(['Grecia'])
        ->and($today[0]['subtitle'])->toBe('day 4 of 9')
        ->and($today[0])->not->toHaveKey('toggleUrl')
        ->and($week->pluck('title')->all())->toBe(['Dentist'])->and($week[0]['subtitle'])->toBe('09:00–10:00');
});

it('shows multi-day events now and in the next 30 days in the Now & next card', function () {
    Carbon::setTestNow('2026-07-20 10:00');
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    trip($users[0]); // now
    trip($users[0], ['title' => 'Tabăra', 'start_date' => '2026-07-26', 'end_date' => '2026-08-01']); // in 6 days
    trip($users[0], ['title' => 'Retreat', 'start_date' => '2026-09-10', 'end_date' => '2026-09-12']); // > 30 days
    trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-07-21', 'end_date' => '2026-07-21']); // single day

    $w = DashboardData::build()['widgets']['calendar'];
    expect(collect($w)->pluck('title')->all())->toBe(['Grecia', 'Tabăra'])
        ->and($w[0])->toMatchArray(['now' => true, 'dayOf' => 4, 'days' => 9])
        ->and($w[1])->toMatchArray(['now' => false, 'inDays' => 6]);
});

it('hides the card when nothing multi-day is coming', function () {
    Carbon::setTestNow('2026-07-20 10:00');
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    expect(DashboardData::build()['widgets']['calendar'] ?? null)->toBeNull();
});
