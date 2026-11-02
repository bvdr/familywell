<?php

use Modules\Calendar\CalendarRange;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// Calendar views: the date window each one shows, and the calendar 1.1 preview remembering the last view picked.

it('gives each view its window', function (string $view, string $zoom, array $range) {
    expect(CalendarRange::for($view, $zoom, '2026-10-21'))->toBe($range);
})->with([
    'agenda: Monday..Sunday' => ['agenda', 'twoweeks', ['2026-10-19', '2026-10-25']],
    'timeline 1w' => ['timeline', 'week', ['2026-10-19', '2026-10-25']],
    'timeline 2w' => ['timeline', 'twoweeks', ['2026-10-19', '2026-11-01']],
    'timeline 5w (desktop month)' => ['timeline', 'month', ['2026-10-19', '2026-11-22']],
    'month grid: six weeks' => ['month', 'month', ['2026-09-28', '2026-11-08']],
]);

it('opens on the remembered view for everyone; the URL wins and junk is ignored', function () {
    [, [$user]] = calendarFamily(['calendar']);

    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'timeline')->get('/m/calendar?date=2026-10-21')
        ->assertInertia(fn ($p) => $p->where('view', 'timeline'));
    // The old week view (a remembered cookie, an old link) opens the agenda that replaced it.
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'week')->get('/m/calendar?date=2026-10-21')
        ->assertInertia(fn ($p) => $p->where('view', 'agenda')->where('range.from', '2026-10-19'));
    // An explicit view in the URL wins over the remembered one; junk in the cookie is ignored (agenda first).
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'timeline')->get('/m/calendar?view=month')->assertInertia(fn ($p) => $p->where('view', 'month'));
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'bogus')->get('/m/calendar')->assertInertia(fn ($p) => $p->where('view', 'agenda'));
});

it('serves items for a range as JSON for the endless timeline, within the family and range cap', function () {
    [, [$user, $other]] = calendarFamily(['calendar']);
    actingAs($user);
    trip($user, ['title' => 'Mine', 'start_date' => '2027-02-03', 'end_date' => '2027-02-05']);
    $secret = trip($other, ['title' => 'Secret', 'start_date' => '2027-02-04', 'end_date' => '2027-02-04']);
    $secret->setVisibility('private');

    $this->getJson('/m/calendar/items?from=2027-02-01&to=2027-02-28')->assertOk()
        ->assertJsonPath('items.0.title', 'Mine')->assertJsonCount(1, 'items');
    $this->getJson('/m/calendar/items?from=2027-02-01&to=2028-06-01')->assertStatus(422); // over the range cap
    $this->getJson('/m/calendar/items?from=2027-02-10&to=2027-02-01')->assertStatus(422); // backwards
});

it('gives the timeline the next things that take a day after its window, not timed ones', function () {
    [, [$user]] = calendarFamily(['calendar']);
    actingAs($user);
    trip($user, ['title' => 'In the window', 'start_date' => '2026-10-22', 'end_date' => '2026-10-23']);
    trip($user, ['title' => 'Dentist', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'start_time' => '15:00', 'end_time' => '16:00']);
    trip($user, ['title' => 'Sibiu', 'start_date' => '2026-11-28', 'end_date' => '2026-12-01']);

    $this->get('/m/calendar?view=timeline&zoom=twoweeks&date=2026-10-21')->assertInertia(fn ($p) => $p
        ->where('range.to', '2026-11-01')->has('ahead', 1)->where('ahead.0.title', 'Sibiu'));
});
