<?php

use App\Support\Tenancy\CurrentFamily;
use Modules\Calendar\CalendarEvent;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// The calendar's right-click menu (design 04b D3 / D4): Edit / Duplicate read the event's form values, Delete is only
// offered to its creator, and To-do due <day> loads the lists when its sheet opens.

it('sends an event its sheet values for Edit and Duplicate, to people who can see it only', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0], ['category' => 'trips', 'notes' => 'Ferry at 7']);

    actingAs($users[1])->getJson("/m/calendar/events/{$e->id}/form")->assertOk()
        ->assertJson(['id' => $e->id, 'title' => 'Grecia', 'notes' => 'Ferry at 7', 'category' => 'trips', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25']);

    $e->setVisibility('private');
    actingAs($users[1])->getJson("/m/calendar/events/{$e->id}/form")->assertForbidden();

    [, $others] = calendarFamily();
    CurrentFamily::forget(); // a real request binds the route before the family scope (see EventsTest)
    actingAs($others[0])->getJson("/m/calendar/events/{$e->id}/form")->assertForbidden();
});

it('marks only the creator\'s events as deletable', function () {
    [, $users] = calendarFamily();
    trip($users[0]);

    expect(CalendarEvent::itemsFor($users[0]->id, '2026-07-01', '2026-07-31')[0]['deletable'])->toBeTrue()
        ->and(CalendarEvent::itemsFor($users[1]->id, '2026-07-01', '2026-07-31')[0]['deletable'])->toBeFalse();
});

it('loads the to-do lists only when asked for', function () {
    [, $users] = calendarFamily();
    TaskList::createFor($users[0], ['name' => 'Household']);

    actingAs($users[0])->get('/m/calendar')->assertInertia(fn ($p) => $p->missing('todoLists')
        ->reloadOnly('todoLists', fn ($reload) => $reload->where('todoLists.0.label', 'Household')));
});
