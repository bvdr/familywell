<?php

use Modules\Calendar\CalendarCategory;
use Modules\Calendar\CalendarEvent;
use Modules\Calendar\IconGuess;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// The same cases the JS twin runs (resources/js/lib/icon-guess.test.mjs): if one side drifts, its own run fails.
it('guesses icons from the title like the browser does', function () {
    $cases = json_decode(file_get_contents(base_path('tests/fixtures/calendar-icon-guess.json')), true)['cases'];
    foreach ($cases as $c) {
        expect(IconGuess::guess($c['title']))->toBe(['word' => $c['word'], 'icons' => $c['icons']], $c['title']);
    }
});

it('keeps the word table clean: no short prefixes, ASCII only, and only icons that render without a request', function () {
    $groups = json_decode(file_get_contents(base_path('modules/calendar/icon-guess.json')), true)['groups'];
    // The keys of STATIC_ICONS in lucide-icon.tsx: an icon outside it would flash in lazily on every agenda row.
    preg_match_all("/^\s+'?([a-z0-9-]+)'?: [A-Z]\w+,$/m", file_get_contents(base_path('resources/js/components/lucide-icon.tsx')), $m);
    $static = $m[1];
    expect($static)->toContain('church', 'flag');

    foreach ($groups as $g) {
        foreach ($g['words'] as $w) {
            expect($w)->toMatch('/^[a-z0-9]+\$?$/', $w);
            // A prefix under 4 letters would catch unrelated words ("car" in "carnaval"): those must be exact (w$).
            if (! str_ends_with($w, '$')) {
                expect(strlen($w))->toBeGreaterThanOrEqual(4, $w);
            }
        }
        foreach ($g['icons'] as $icon) {
            expect($static)->toContain($icon);
        }
    }
    foreach (CalendarCategory::BUILT_IN as $d) {
        expect($static)->toContain($d['icon']);
    }
});

it('shows the picked icon, else the title guess, else the category icon, else a calendar', function () {
    [, $users] = calendarFamily();
    $day = ['start_date' => '2026-07-17', 'end_date' => '2026-07-17'];
    CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Parastas', 'icon' => 'gem']));
    CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Parastas Mamaie']));
    CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Tim', 'category' => 'trips']));
    CalendarEvent::createFor($users[0], CalendarEvent::validated($day + ['title' => 'Tim 2']));

    $icons = collect(CalendarEvent::itemsFor($users[0]->id, '2026-07-01', '2026-07-31'))->pluck('icon', 'title');

    expect($icons['Parastas'])->toBe('gem')            // picked
        ->and($icons['Parastas Mamaie'])->toBe('church') // guessed
        ->and($icons['Tim'])->toBe('plane')             // category's (trips)
        ->and($icons['Tim 2'])->toBe('calendar');       // nothing to go on
});

it('uses a family category icon when the title gives no guess, and the guess follows a later rename', function () {
    [, $users] = calendarFamily();
    $cat = CalendarCategory::add(['name' => 'Sports', 'icon' => 'trophy', 'color' => 'teal']);
    $e = CalendarEvent::createFor($users[0], CalendarEvent::validated([
        'title' => 'Tim', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17', 'category' => $cat->slug,
    ]));
    $icon = fn () => CalendarEvent::itemsFor($users[0]->id, '2026-07-01', '2026-07-31')[0]['icon'];
    expect($icon())->toBe('trophy');

    // icon was never picked (null), so it keeps following the title.
    actingAs($users[0])->patch("/m/calendar/events/{$e->id}", ['title' => 'Dentist Tim'])->assertSessionHasNoErrors();
    expect($e->fresh()->icon)->toBeNull()->and($icon())->toBe('stethoscope');
});
