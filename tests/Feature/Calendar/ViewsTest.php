<?php

use Modules\Calendar\CalendarRange;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// Calendar views: the date window each one shows, and the calendar 1.1 preview remembering the last view picked.

it('gives each view its window', function (string $view, string $zoom, array $range) {
    expect(CalendarRange::for($view, $zoom, '2026-10-21'))->toBe($range);
})->with([
    'week view: Monday..Sunday' => ['week', 'month', ['2026-10-19', '2026-10-25']],
    'timeline week: two weeks' => ['timeline', 'week', ['2026-10-19', '2026-11-01']],
    'timeline month' => ['timeline', 'month', ['2026-10-01', '2026-10-31']],
    'timeline year' => ['timeline', 'year', ['2026-01-01', '2026-12-31']],
    'month grid: six weeks' => ['month', 'month', ['2026-09-28', '2026-11-08']],
]);

it('opens on the remembered view for everyone; the URL wins and junk is ignored', function () {
    [, [$user]] = calendarFamily(['calendar']);

    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'timeline')->get('/m/calendar?date=2026-10-21')
        ->assertInertia(fn ($p) => $p->where('view', 'timeline'));
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'week')->get('/m/calendar?date=2026-10-21')
        ->assertInertia(fn ($p) => $p->where('view', 'week')->where('range.from', '2026-10-19'));
    // An explicit view in the URL wins over the remembered one; junk in the cookie is ignored.
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'timeline')->get('/m/calendar?view=week')->assertInertia(fn ($p) => $p->where('view', 'week'));
    actingAs($user)->withUnencryptedCookie('fw_calendar_view', 'bogus')->get('/m/calendar')->assertInertia(fn ($p) => $p->where('view', 'month'));
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
