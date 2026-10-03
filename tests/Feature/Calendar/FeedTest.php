<?php

use App\Support\Tenancy\CurrentFamily;
use Modules\Birthdays\BirthdayDate;
use Modules\Calendar\CalendarFeed;
use Modules\Contacts\Person;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

it('serves events and birthdays as ICS with repeat rules, by token only', function () {
    [, $users] = calendarFamily(['calendar', 'tasks', 'contacts', 'birthdays']);
    trip($users[0]); // all-day 17-25 Jul
    trip($users[0], ['title' => 'Gym; legs', 'start_date' => '2026-07-06', 'end_date' => '2026-07-06', 'start_time' => '18:00', 'end_time' => '19:00', 'repeat' => 'weekly', 'repeat_interval' => 2, 'repeat_until' => '2026-12-31']);
    $ana = Person::create(['name' => 'Ana', 'created_by' => $users[0]->id]);
    BirthdayDate::create(['person_id' => $ana->id, 'month' => 7, 'day' => 20, 'birthdate' => '1986-07-20']);

    actingAs($users[0])->post('/m/calendar/feed')->assertRedirect();
    $token = CalendarFeed::where('user_id', $users[0]->id)->value('token');
    auth()->logout();
    CurrentFamily::forget();

    $ics = $this->get("/calendar/feed/{$token}.ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain("DTSTART;VALUE=DATE:20260717\r\nDTEND;VALUE=DATE:20260726\r\n") // exclusive end
        ->toContain("SUMMARY:Gym\\; legs\r\n")
        ->toContain("DTSTART:20260706T180000\r\nDTEND:20260706T190000\r\n") // floating local time
        ->toContain('RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231T235959')
        ->toContain("RRULE:FREQ=YEARLY\r\n")
        ->toContain('SUMMARY:Ana');
});

it('stops serving the old URL after a reset, and 404s for unknown tokens', function () {
    [, $users] = calendarFamily();
    $old = CalendarFeed::rotate($users[0]->id)->token;
    $new = CalendarFeed::rotate($users[0]->id)->token;
    auth()->logout();
    $this->get("/calendar/feed/{$old}.ics")->assertNotFound();
    $this->get("/calendar/feed/{$new}.ics")->assertOk();
    $this->get('/calendar/feed/nope.ics')->assertNotFound();
});

it('leaves out events the subscriber cannot see', function () {
    [, $users] = calendarFamily();
    trip($users[0], ['title' => 'Secret'])->setVisibility('private');
    $token = CalendarFeed::rotate($users[1]->id)->token;
    auth()->logout();
    expect($this->get("/calendar/feed/{$token}.ics")->getContent())->not->toContain('Secret');
});

it('folds long lines at 75 octets', function () {
    [, $users] = calendarFamily();
    trip($users[0], ['title' => str_repeat('Călătorie lungă ', 10)]);
    $token = CalendarFeed::rotate($users[0]->id)->token;
    auth()->logout();
    foreach (explode("\r\n", $this->get("/calendar/feed/{$token}.ics")->getContent()) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

it('404s a feed once its owner is no longer a member of the family', function () {
    [$family, $users] = calendarFamily();
    $token = CalendarFeed::rotate($users[1]->id)->token;
    auth()->logout();
    $this->get("/calendar/feed/{$token}.ics")->assertOk();

    $family->members()->detach($users[1]->id);

    $this->get("/calendar/feed/{$token}.ics")->assertNotFound();
});

it('throttles a flood of requests for the same token', function () {
    [, $users] = calendarFamily();
    $token = CalendarFeed::rotate($users[0]->id)->token;
    auth()->logout();
    for ($i = 0; $i < 30; $i++) {
        $this->get("/calendar/feed/{$token}.ics")->assertOk();
    }
    $this->get("/calendar/feed/{$token}.ics")->assertStatus(429);
});

it('renders the birthday summary in the feed owner\'s locale', function () {
    [, $users] = calendarFamily(['calendar', 'contacts', 'birthdays']);
    $users[0]->update(['locale' => 'ro']);
    $ana = Person::create(['name' => 'Ana', 'created_by' => $users[0]->id]);
    BirthdayDate::create(['person_id' => $ana->id, 'month' => 7, 'day' => 20, 'birthdate' => '1986-07-20']);
    $token = CalendarFeed::rotate($users[0]->id)->token;
    auth()->logout();

    expect($this->get("/calendar/feed/{$token}.ics")->getContent())->toContain('SUMMARY:Ziua lui Ana');
});
