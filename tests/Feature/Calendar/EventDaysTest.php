<?php

use Illuminate\Support\Facades\DB;
use Modules\Calendar\CalendarEvent;
use Modules\Calendar\CalendarEventDayNote;
use Modules\Calendar\CalendarEventMark;
use App\Support\Calendar\CalendarSources;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// Days inside a multi-day event (design C15-C17): marks by day index, a note per date, the day-card payload.

// A 5-day fast: Mon 2026-11-16 .. Fri 2026-11-20 (indexes 0..4).
function fast(array $over = []): array
{
    [, $users] = calendarFamily();
    actingAs($users[0]);

    return [trip($users[0], $over + ['title' => 'Fast', 'start_date' => '2026-11-16', 'end_date' => '2026-11-20']), $users];
}

it('creates a mark on a pattern of days, stored by day index', function () {
    [$e, $users] = fast();

    // Wed + Fri of the week = indexes 2 and 4, sent out of order and the mark must come back sorted.
    actingAs($users[0])->post("/m/calendar/events/{$e->id}/marks", ['name' => 'Fish day', 'icon' => 'fish', 'days' => [4, 2]])->assertRedirect();

    $mark = CalendarEventMark::firstOrFail();
    expect($mark->event_id)->toBe($e->id)->and($mark->name)->toBe('Fish day')->and($mark->icon)->toBe('fish')->and($mark->days)->toBe([2, 4]);
});

it('rejects marks outside the event, on one-day events, and too many', function () {
    [$e, $users] = fast();
    $url = "/m/calendar/events/{$e->id}/marks";

    actingAs($users[0])->post($url, ['name' => 'x', 'days' => [5]])->assertSessionHasErrors('days.0'); // only 0..4
    actingAs($users[0])->post($url, ['name' => 'x', 'days' => [1, 1]])->assertSessionHasErrors('days.0'); // distinct
    actingAs($users[0])->post($url, ['name' => '', 'days' => [1]])->assertSessionHasErrors('name');
    actingAs($users[0])->post($url, ['name' => 'x'])->assertSessionHasErrors('days'); // present, even when empty

    $one = trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-11-16', 'end_date' => '2026-11-16']);
    actingAs($users[0])->post("/m/calendar/events/{$one->id}/marks", ['name' => 'x', 'days' => [0]])->assertStatus(422);

    foreach (range(1, CalendarEventMark::MAX_PER_EVENT) as $i) {
        $e->marks()->create(['name' => "m{$i}", 'days' => [0]]);
    }
    actingAs($users[0])->post($url, ['name' => 'one too many', 'days' => [0]])->assertStatus(422);
    expect($e->marks()->count())->toBe(CalendarEventMark::MAX_PER_EVENT);
});

it('toggles a day by saving the whole set, renames, and deletes a mark', function () {
    [$e, $users] = fast();
    $m = $e->marks()->create(['name' => 'Fish', 'icon' => 'fish', 'days' => [2]]);
    $url = "/m/calendar/events/{$e->id}/marks/{$m->id}";

    actingAs($users[0])->patch($url, ['days' => [2, 3]])->assertRedirect();
    expect($m->fresh()->days)->toBe([2, 3])->and($m->fresh()->name)->toBe('Fish'); // a partial update leaves the rest

    actingAs($users[0])->patch($url, ['days' => []])->assertRedirect(); // a mark with no days is allowed (untick all)
    actingAs($users[0])->patch($url, ['name' => 'Fish and oil'])->assertRedirect();
    expect($m->fresh()->days)->toBe([])->and($m->fresh()->name)->toBe('Fish and oil');

    actingAs($users[0])->delete($url)->assertRedirect();
    expect(CalendarEventMark::count())->toBe(0);
});

it('only lets people who can edit the event touch its marks and notes', function () {
    [$e, $users] = fast();
    $e->setVisibility('private'); // nobody but the owner
    $m = $e->marks()->create(['name' => 'Fish', 'days' => [1]]);

    actingAs($users[1])->post("/m/calendar/events/{$e->id}/marks", ['name' => 'x', 'days' => [0]])->assertForbidden();
    actingAs($users[1])->patch("/m/calendar/events/{$e->id}/marks/{$m->id}", ['days' => [0]])->assertForbidden();
    actingAs($users[1])->delete("/m/calendar/events/{$e->id}/marks/{$m->id}")->assertForbidden();
    actingAs($users[1])->put("/m/calendar/events/{$e->id}/days/2026-11-17/note", ['body' => 'hi'])->assertForbidden();
    expect($m->fresh()->days)->toBe([1])->and(CalendarEventDayNote::count())->toBe(0);
});

it('never reaches another event\'s mark through this event', function () {
    [$e, $users] = fast();
    $other = trip($users[0], ['title' => 'Other', 'start_date' => '2026-11-16', 'end_date' => '2026-11-18']);
    $m = $other->marks()->create(['name' => 'Theirs', 'days' => [0]]);

    actingAs($users[0])->patch("/m/calendar/events/{$e->id}/marks/{$m->id}", ['days' => [1]])->assertNotFound();
    actingAs($users[0])->delete("/m/calendar/events/{$e->id}/marks/{$m->id}")->assertNotFound();
    expect($m->fresh()->days)->toBe([0]);
});

it('keeps a note per date: write, change, clear, and refuse dates outside the event', function () {
    [$e, $users] = fast();
    $url = fn (string $d) => "/m/calendar/events/{$e->id}/days/{$d}/note";

    actingAs($users[0])->put($url('2026-11-18'), ['body' => "  Tim's cake is a fast one  "])->assertRedirect();
    expect(CalendarEventDayNote::sole()->body)->toBe("Tim's cake is a fast one")->and(CalendarEventDayNote::sole()->date)->toBe('2026-11-18');

    actingAs($users[0])->put($url('2026-11-18'), ['body' => 'changed'])->assertRedirect();
    expect(CalendarEventDayNote::count())->toBe(1)->and(CalendarEventDayNote::sole()->body)->toBe('changed');

    actingAs($users[0])->put($url('2026-11-21'), ['body' => 'after the end'])->assertStatus(422);
    actingAs($users[0])->put($url('2026-02-30'), ['body' => 'not a date'])->assertStatus(422);
    actingAs($users[0])->put($url('2026-11-18'), ['body' => str_repeat('x', 501)])->assertSessionHasErrors('body');

    actingAs($users[0])->put($url('2026-11-18'), ['body' => ''])->assertRedirect(); // empty clears it
    expect(CalendarEventDayNote::count())->toBe(0);
});

it('puts marks, notes and open to-do counts on the multi-day item, by day index', function () {
    [$e, $users] = fast();
    $e->marks()->create(['name' => 'Fish', 'icon' => 'fish', 'days' => [2, 4]]);
    $e->dayNotes()->create(['date' => '2026-11-18', 'body' => 'Cake']);
    $e->addTask($users[0], ['title' => 'Buy coconut cream', 'due_date' => '2026-11-18']);
    $done = $e->addTask($users[0], ['title' => 'Already done', 'due_date' => '2026-11-19']);
    $done->update(['done' => true]);
    $single = trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
    $single->marks()->create(['name' => 'ignored', 'days' => [0]]); // can't happen via the API; must not leak into items

    $items = collect(CalendarSources::collect('2026-11-14', '2026-11-30', $users[0]->id))->where('kind', 'event')->keyBy('title');

    $days = json_decode(json_encode($items['Fast']['days']), true);
    expect($days['marks'])->toHaveCount(1)
        ->and($days['marks'][0])->toMatchArray(['name' => 'Fish', 'icon' => 'fish', 'days' => [2, 4]])
        ->and($days['notes'])->toBe(['2' => 'Cake'])        // 2026-11-18 is day index 2
        ->and($days['todos'])->toBe(['2' => 1]);            // the done to-do on the 19th doesn't count
    expect($items['Dentist'])->not->toHaveKey('days');
});

it('carries marks to the next occurrence of a repeating event but not that date\'s note', function () {
    [$e, $users] = fast(['repeat' => 'yearly']);
    $e->marks()->create(['name' => 'Fish', 'days' => [1, 3]]);
    $e->dayNotes()->create(['date' => '2026-11-17', 'body' => 'This year only']);

    $items = collect(CalendarSources::collect('2026-11-01', '2027-11-30', $users[0]->id))->where('title', 'Fast')->values();
    expect($items)->toHaveCount(2); // 2026 and 2027 occurrences
    [$first, $next] = $items->map(fn ($i) => json_decode(json_encode($i['days']), true));

    expect($first['marks'][0]['days'])->toBe([1, 3])->and($next['marks'][0]['days'])->toBe([1, 3])
        ->and($first['notes'])->toBe(['1' => 'This year only'])->and($next['notes'])->toBe([]);
});

it('adds the day data in two queries however many multi-day events there are', function () {
    [$e, $users] = fast();
    $queries = function () use ($users) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        CalendarEvent::itemsFor($users[0]->id, '2026-11-14', '2026-11-30', fn () => []);

        return count(DB::getQueryLog());
    };
    $baseline = $queries();

    foreach (range(1, 6) as $i) {
        $x = trip($users[0], ['title' => "Trip {$i}", 'start_date' => '2026-11-17', 'end_date' => '2026-11-22']);
        $x->marks()->create(['name' => 'm', 'days' => [0]]);
        $x->dayNotes()->create(['date' => '2026-11-18', 'body' => 'n']);
    }

    expect($queries())->toBe($baseline); // 7 events, same number of queries as 1
    // Home and MCP don't opt in, so they pay nothing for it.
    DB::flushQueryLog();
    CalendarEvent::itemsFor($users[0]->id, '2026-11-14', '2026-11-30');
    $without = count(DB::getQueryLog());
    expect($baseline - $without)->toBe(2); // exactly the marks query and the notes query
});

it('opens the event page with its days, the occurrence a date belongs to, and what is coming up inside', function () {
    [$e, $users] = fast(['repeat' => 'yearly']);
    $e->marks()->create(['name' => 'Fish', 'days' => [2]]);
    $e->dayNotes()->create(['date' => '2027-11-17', 'body' => 'Next year note']);
    trip($users[0], ['title' => 'Church visit', 'start_date' => '2027-11-18', 'end_date' => '2027-11-18']);

    actingAs($users[0])->get("/m/calendar/events/{$e->id}")
        ->assertInertia(fn ($p) => $p->where('days.start', '2026-11-16')->where('days.end', '2026-11-20')->has('days.marks', 1)->where('openDay', null));

    // ?day= in next year's run opens that occurrence: its dates, its notes, and the other things inside it.
    actingAs($users[0])->get("/m/calendar/events/{$e->id}?day=2027-11-17")
        ->assertInertia(fn ($p) => $p->where('days.start', '2027-11-16')->where('days.end', '2027-11-20')
            ->where('days.notes', ['2027-11-17' => 'Next year note'])
            ->where('days.inside.0.title', 'Church visit')->where('openDay', '2027-11-17'));
});

it('gives a one-day event no days', function () {
    [, $users] = calendarFamily();
    $one = trip($users[0], ['title' => 'Dentist', 'start_date' => '2026-11-16', 'end_date' => '2026-11-16']);

    actingAs($users[0])->get("/m/calendar/events/{$one->id}")->assertInertia(fn ($p) => $p->where('days', null));
});

it('deletes marks and notes with the event when it is erased, and keeps them while it sits in the trash', function () {
    [$e] = fast();
    $e->marks()->create(['name' => 'Fish', 'days' => [1]]);
    $e->dayNotes()->create(['date' => '2026-11-17', 'body' => 'n']);

    $e->delete(); // trash: the 30 days to change your mind
    expect(CalendarEventMark::count())->toBe(1)->and(CalendarEventDayNote::count())->toBe(1);
    $e->restore();
    expect($e->marks()->count())->toBe(1);

    $e->forceDelete(); // trash:purge
    expect(CalendarEventMark::count())->toBe(0)->and(CalendarEventDayNote::count())->toBe(0);
});

it('gives every loaded copy of an occurrence its whole notes and to-dos, not just the requested days', function () {
    // The timeline loads its strip in chunks and keeps the last copy of each occurrence it loaded: a chunk that ends
    // before a day with a note must still carry that note, or the day card loses it when the chunk lands.
    [$e, $users] = fast(['start_date' => '2026-11-15', 'end_date' => '2026-12-24']);
    $e->dayNotes()->create(['date' => '2026-12-10', 'body' => 'Sf. Nicolae']);
    $items = CalendarEvent::itemsFor($users[0]->id, '2026-11-01', '2026-11-20', fn () => [$e->id => ['2026-12-10' => 2]]);
    $days = collect($items)->firstWhere('id', $e->id)['days'];

    expect((array) $days['notes'])->toBe([25 => 'Sf. Nicolae'])
        ->and((array) $days['todos'])->toBe([25 => 2]);
});
