<?php

use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\DB;
use Modules\Calendar\CalendarEvent;
use Modules\Contacts\Person;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

it('creates an event with people and shows it on the calendar page', function () {
    [$family, $users] = calendarFamily();
    $simona = Person::create(['name' => 'Simona', 'created_by' => $users[0]->id]);

    actingAs($users[0])->post('/m/calendar/events', [
        'title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25', 'icon' => 'car',
        'people' => [['type' => 'user', 'id' => $users[1]->id], ['type' => 'person', 'id' => $simona->id]],
    ])->assertRedirect();

    $event = CalendarEvent::firstOrFail();
    expect($event->people())->toBe([
        ['type' => 'user', 'id' => $users[1]->id, 'name' => $users[1]->name],
        ['type' => 'person', 'id' => $simona->id, 'name' => 'Simona'],
    ]);

    actingAs($users[0])->get('/m/calendar?view=month&date=2026-07-10')->assertOk()
        ->assertInertia(fn ($p) => $p->component('calendar/index')
            ->where('range', ['from' => '2026-06-29', 'to' => '2026-08-09'])
            ->where('items.0.title', 'Grecia')->where('items.0.start', '2026-07-17')->where('items.0.editable', true));
});

it('rejects an end before the start and a same-day end time before the start time', function () {
    [, $users] = calendarFamily();
    actingAs($users[0])->post('/m/calendar/events', ['title' => 'X', 'start_date' => '2026-07-17', 'end_date' => '2026-07-16'])
        ->assertSessionHasErrors('end_date');
    actingAs($users[0])->post('/m/calendar/events', [
        'title' => 'X', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'start_time' => '10:00', 'end_time' => '09:00',
    ])->assertSessionHasErrors('end_time');
    actingAs($users[0])->post('/m/calendar/events', ['title' => 'X', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'start_time' => '10:00'])
        ->assertSessionHasErrors('end_time');
});

it('updates only the end date against the stored start date', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    actingAs($users[0])->patch("/m/calendar/events/{$e->id}", ['end_date' => '2026-07-27'])->assertSessionHasNoErrors();
    expect($e->fresh()->end_date->toDateString())->toBe('2026-07-27');
    actingAs($users[0])->patch("/m/calendar/events/{$e->id}", ['end_date' => '2026-07-01'])->assertSessionHasErrors('end_date');
});

it('ignores people from another family', function () {
    [, $users] = calendarFamily();
    $stranger = User::factory()->create();
    actingAs($users[0])->post('/m/calendar/events', [
        'title' => 'X', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'people' => [['type' => 'user', 'id' => $stranger->id]],
    ])->assertSessionHasErrors('people');
});

it('drops a deleted contact from the event', function () {
    [, $users] = calendarFamily();
    $simona = Person::create(['name' => 'Simona', 'created_by' => $users[0]->id]);
    $e = trip($users[0], ['people' => [['type' => 'person', 'id' => $simona->id]]]);
    actingAs($users[0])->delete("/m/contacts/people/{$simona->id}")->assertRedirect();
    expect($e->fresh()->people())->toBe([]);
});

it('keeps private events private and blocks other families', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    $e->setVisibility('private');
    actingAs($users[1])->get("/m/calendar/events/{$e->id}")->assertForbidden();
    actingAs($users[1])->get('/m/calendar?date=2026-07-10')->assertInertia(fn ($p) => $p->where('items', []));

    [, $others] = calendarFamily();
    // calendarFamily() sets CurrentFamily::id() directly (needed for the synchronous CalendarEvent calls
    // other tests make without an HTTP round trip). A real request never carries that leftover value in:
    // SetActiveFamily (which derives it from the session) runs AFTER route-model binding, so at bind time
    // CurrentFamily is whatever a prior request left it — null on a fresh process/Octane worker. Forgetting
    // it here reproduces that so the family scope doesn't 404 the binding before canView() gets to say 403.
    CurrentFamily::forget();
    actingAs($others[0])->get("/m/calendar/events/{$e->id}")->assertForbidden();
});

it('expands a repeating event across the range', function () {
    [, $users] = calendarFamily();
    trip($users[0], ['title' => 'Gym', 'start_date' => '2026-07-06', 'end_date' => '2026-07-06', 'repeat' => 'weekly']);
    $items = CalendarEvent::itemsFor($users[0]->id, '2026-07-01', '2026-07-31');
    expect(array_column($items, 'start'))->toBe(['2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27']);
});

it('computes editable from shares and visibility, with no per-event query', function () {
    [, $users] = calendarFamily();
    $open = trip($users[0], ['title' => 'Family Trip']);
    $restricted = trip($users[0], ['title' => 'Private Review']);
    $restricted->setVisibility('invited');
    $restricted->shares()->create(['user_id' => $users[1]->id, 'role' => 'viewer']);

    DB::enableQueryLog();
    $items = CalendarEvent::itemsFor($users[1]->id, '2026-07-01', '2026-07-31');
    $queriesForTwo = count(DB::getQueryLog());
    DB::flushQueryLog();

    $byTitle = collect($items)->keyBy('title');
    // Family-visible, no explicit share for $users[1]: visibility() === 'family' is enough (the query
    // is already family-scoped + visibleTo-filtered, so membership is implied).
    expect($byTitle['Family Trip']['editable'])->toBeTrue();
    // Explicit 'viewer' share on a non-family-visible event: below the 'editor' bar ShareAccess::canEdit uses.
    expect($byTitle['Private Review']['editable'])->toBeFalse();

    // Adding more events must not add more queries: editable is read off the already-eager-loaded
    // shares/shareSetting relations, not a ShareAccess query per event (that was the bug being fixed).
    DB::disableQueryLog();
    trip($users[0], ['title' => 'C', 'start_date' => '2026-07-02', 'end_date' => '2026-07-02']);
    trip($users[0], ['title' => 'D', 'start_date' => '2026-07-03', 'end_date' => '2026-07-03']);
    trip($users[0], ['title' => 'E', 'start_date' => '2026-07-04', 'end_date' => '2026-07-04']);

    DB::enableQueryLog();
    CalendarEvent::itemsFor($users[1]->id, '2026-07-01', '2026-07-31');
    $queriesForFive = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesForFive)->toBe($queriesForTwo);
});

it('trashes and restores an event', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    actingAs($users[0])->delete("/m/calendar/events/{$e->id}")->assertRedirect('/m/calendar');
    $this->assertSoftDeleted('calendar_events', ['id' => $e->id]);
    // Restore route is Settings > Recently deleted: POST /settings/recently-deleted/{module}/{type}/{id}
    // (see tests/Feature/RecentlyDeletedTest.php) — the brief's assumed '/settings/trash/restore' URL
    // doesn't exist in this codebase.
    actingAs($users[0])->post("/settings/recently-deleted/calendar/calendar_event/{$e->id}")->assertRedirect();
    expect(CalendarEvent::find($e->id))->not->toBeNull();
});

it('rejects a range longer than 400 days', function () {
    [, $users] = calendarFamily();
    expect(fn () => CalendarEvent::itemsFor($users[0]->id, '2026-01-01', '2027-03-01'))->toThrow(InvalidArgumentException::class);
});
