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

it('opens on the remembered view for a preview user, and only for them', function () {
    [, [$preview, $other]] = calendarFamily(['calendar']);
    $preview->forceFill(['preview_modules' => ['calendar']])->save();

    actingAs($preview)->withUnencryptedCookie('fw_calendar_view', 'timeline:year')->get('/m/calendar?date=2026-10-21')
        ->assertInertia(fn ($p) => $p->where('view', 'timeline')->where('zoom', 'year')->where('range.to', '2026-12-31'));
    // An explicit view in the URL wins over the remembered one; junk in the cookie is ignored.
    actingAs($preview)->withUnencryptedCookie('fw_calendar_view', 'timeline:year')->get('/m/calendar?view=week')->assertInertia(fn ($p) => $p->where('view', 'week'));
    actingAs($preview)->withUnencryptedCookie('fw_calendar_view', 'bogus')->get('/m/calendar')->assertInertia(fn ($p) => $p->where('view', 'month'));

    actingAs($other)->withUnencryptedCookie('fw_calendar_view', 'timeline:year')->get('/m/calendar')
        ->assertInertia(fn ($p) => $p->where('view', 'month')->where('zoom', 'month'));
});
