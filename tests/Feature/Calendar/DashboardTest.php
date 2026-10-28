<?php

use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\CalendarEvent;

require_once __DIR__.'/helpers.php';

it('puts an ongoing trip on Today once with day N of M, and a later one in This Week', function () {
    Carbon::setTestNow('2026-07-20 10:00'); // Monday
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    trip($users[0]); // Grecia 17-25 Jul
    trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-07-23', 'end_date' => '2026-07-23', 'start_time' => '09:00', 'end_time' => '10:00']);

    $d = DashboardData::build();
    $today = collect($d['today'])->where('kind', 'event')->values();
    $week = collect($d['later'])->where('kind', 'event')->values();
    expect($today->pluck('title')->all())->toBe(['Grecia'])
        ->and($today[0]['subtitle'])->toBe('day 4 of 9')
        ->and($today[0])->not->toHaveKey('toggleUrl')
        ->and($week->pluck('title')->all())->toBe(['Dentist'])->and($week[0]['subtitle'])->toBe('09:00–10:00')
        ->and($week[0])->toMatchArray(['time' => '09:00', 'endTime' => '10:00', 'eventId' => $week[0]['id']]);
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

it('surfaces open dated event to-dos (and sub-tasks) on Today/This Week with a toggle, and on the calendar', function () {
    Carbon::setTestNow('2026-07-20 10:00'); // Monday
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    $e = trip($users[0]);
    $hotel = $e->addTask($users[0], ['title' => 'Book hotel', 'due_date' => '2026-07-18', 'assigned_to' => $users[1]->id]); // overdue
    $e->addTask($users[0], ['title' => 'Passports', 'parent_id' => $hotel->id, 'due_date' => '2026-07-23']); // later this week
    $e->addTask($users[0], ['title' => 'Done', 'due_date' => '2026-07-20'])->update(['done' => true]);
    $e->addTask($users[0], ['title' => 'Undated']);
    $secret = trip($users[1], ['title' => 'Secret']);
    $secret->setVisibility('private');
    $secret->addTask($users[1], ['title' => 'Hidden', 'due_date' => '2026-07-20']);

    $d = DashboardData::build();
    $today = collect($d['today'])->where('kind', 'calendar_todo')->values();
    $week = collect($d['later'])->where('kind', 'calendar_todo')->values();
    expect($today->pluck('title')->all())->toBe(['Book hotel'])
        ->and($today[0])->toMatchArray(['subtitle' => 'Grecia', 'href' => "/m/calendar/events/{$e->id}", 'toggleUrl' => "/m/calendar/tasks/{$hotel->id}", 'eventId' => $e->id])
        ->and($today[0]['assignee'])->toBe(['id' => $users[1]->id, 'name' => $users[1]->name])
        ->and($week->pluck('title')->all())->toBe(['Passports']);

    // The toggle URL ticks it off (empty-body PATCH), after which it leaves the dashboard.
    $this->patch($today[0]['toggleUrl'], [])->assertRedirect();
    expect(collect(DashboardData::build()['today'])->where('kind', 'calendar_todo'))->toBeEmpty();

    $items = collect(CalendarSources::collect('2026-07-01', '2026-07-31', $users[0]->id))
        ->where('source', 'calendar')->where('kind', 'task')->values();
    expect($items->pluck('title')->sort()->values()->all())->toBe(['Book hotel', 'Done', 'Passports'])
        ->and($items->firstWhere('title', 'Book hotel'))->toMatchArray(['done' => true, 'href' => "/m/calendar/events/{$e->id}", 'start' => '2026-07-18'])
        ->and($items->firstWhere('title', 'Book hotel')['people'][0]['id'])->toBe($users[1]->id);
});

it('fetches event to-dos in a constant number of queries', function () {
    Carbon::setTestNow('2026-07-20 10:00');
    [, $users] = calendarFamily();
    $this->actingAs($users[0]);
    $count = function () use ($users) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        CalendarEvent::datedTodos($users[0]->id, fn ($q) => $q->where('done', false));

        return count(DB::getQueryLog());
    };
    $e = trip($users[0]);
    $e->addTask($users[0], ['title' => 'A', 'due_date' => '2026-07-21', 'assigned_to' => $users[1]->id]);
    $one = $count();
    foreach (range(1, 4) as $n) {
        $x = trip($users[0], ['title' => "T{$n}"]);
        $t = $x->addTask($users[0], ['title' => "A{$n}", 'due_date' => '2026-07-22', 'assigned_to' => $users[1]->id]);
        $x->addTask($users[0], ['title' => "S{$n}", 'parent_id' => $t->id, 'due_date' => '2026-07-22']);
    }
    expect($count())->toBe($one);
});
