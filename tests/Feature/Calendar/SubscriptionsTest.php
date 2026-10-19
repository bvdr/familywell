<?php

use App\Models\User;
use App\Support\Calendar\CalendarSources;
use Illuminate\Support\Facades\Http;
use Modules\Calendar\CalendarSubscription;
use Modules\Calendar\CalendarSubscriptionEvent;
use Modules\Calendar\IcsFetcher;
use Modules\Calendar\IcsImport;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// Other calendars (issue #222): ICS/webcal subscriptions, read-only in the family calendar.

const SUB_ICS = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//EN
BEGIN:VTIMEZONE
TZID:America/New_York
BEGIN:STANDARD
DTSTART:19701101T020000
RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU
TZOFFSETFROM:-0400
TZOFFSETTO:-0500
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700308T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU
TZOFFSETFROM:-0500
TZOFFSETTO:-0400
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:standup
SUMMARY:Standup
DTSTART;TZID=America/New_York:20261005T090000
DTEND;TZID=America/New_York:20261005T093000
RRULE:FREQ=WEEKLY;COUNT=4
EXDATE;TZID=America/New_York:20261012T090000
END:VEVENT
BEGIN:VEVENT
UID:standup
RECURRENCE-ID;TZID=America/New_York:20261019T090000
SUMMARY:Standup (moved)
DTSTART;TZID=America/New_York:20261020T100000
DTEND;TZID=America/New_York:20261020T103000
END:VEVENT
BEGIN:VEVENT
UID:trip
SUMMARY:School trip
LOCATION:Museum
DTSTART;VALUE=DATE:20261007
DTEND;VALUE=DATE:20261010
END:VEVENT
BEGIN:VEVENT
UID:gone
SUMMARY:Cancelled thing
STATUS:CANCELLED
DTSTART;VALUE=DATE:20261008
END:VEVENT
BEGIN:VEVENT
UID:late
SUMMARY:Late show
DTSTART;TZID=America/New_York:20261009T220000
DTEND;TZID=America/New_York:20261010T000000
END:VEVENT
END:VCALENDAR
ICS;

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00'); // the stored window is relative to today; keep the fixture inside it
    IcsFetcher::$resolveUsing = fn (string $host) => ['93.184.216.34']; // a public address; no real DNS in tests
});
afterEach(fn () => IcsFetcher::$resolveUsing = null);

it('expands repeats, exclusions, moved occurrences, all-day spans and timezones', function () {
    $rows = collect(IcsImport::occurrences(SUB_ICS, 'Europe/Bucharest', '2026-10-01', '2026-10-31'))
        ->sortBy(['start_date', 'start_time'])->values();

    expect($rows->pluck('title')->all())->toBe(['Standup', 'School trip', 'Late show', 'Standup (moved)', 'Standup']);
    // 09:00 New York (EDT, -4) = 16:00 Bucharest (EEST, +3); the 12th is excluded, the 19th moved to the 20th 10:00.
    expect($rows[0])->toMatchArray(['start_date' => '2026-10-05', 'start_time' => '16:00', 'end_time' => '16:30']);
    expect($rows[3])->toMatchArray(['start_date' => '2026-10-20', 'start_time' => '17:00']);
    // After the 25 Oct European clock change Bucharest is +2 while New York is still -4: 15:00.
    expect($rows[4])->toMatchArray(['start_date' => '2026-10-26', 'start_time' => '15:00']);
    // All-day: DTEND is exclusive, so 7..9 October.
    expect($rows[1])->toMatchArray(['start_date' => '2026-10-07', 'end_date' => '2026-10-09', 'start_time' => null, 'location' => 'Museum']);
});

it('ends an event at 23:59 the same day when it ends exactly at midnight', function () {
    $late = collect(IcsImport::occurrences(SUB_ICS, 'America/New_York', '2026-10-01', '2026-10-31'))->firstWhere('title', 'Late show');
    expect($late)->toMatchArray(['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'start_time' => '22:00', 'end_time' => '23:59']);
});

it('rejects something that is not a calendar', function () {
    IcsImport::occurrences('<html>Please sign in</html>', 'UTC', '2026-10-01', '2026-10-31');
})->throws(RuntimeException::class, 'not_calendar');

it('refuses internal addresses and non-http schemes', function (string $url, ?array $ips) {
    if ($ips !== null) {
        IcsFetcher::$resolveUsing = fn () => $ips;
    }
    Http::fake();
    expect(fn () => IcsFetcher::get($url))->toThrow(RuntimeException::class, 'blocked');
    Http::assertNothingSent();
})->with([
    'loopback literal' => ['http://127.0.0.1/cal.ics', null],
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data', null],
    'private via DNS' => ['https://cal.example.com/x.ics', ['10.0.0.5']],
    'one private answer among public ones' => ['https://cal.example.com/x.ics', ['93.184.216.34', '192.168.1.10']],
    'file scheme' => ['file:///etc/passwd', null],
    'credentials in URL' => ['https://user:pass@cal.example.com/x.ics', null],
]);

it('checks every redirect hop', function () {
    IcsFetcher::$resolveUsing = fn (string $host) => $host === 'internal.example.com' ? ['10.1.2.3'] : ['93.184.216.34'];
    Http::fake([
        'cal.example.com/*' => Http::response('', 302, ['Location' => 'http://internal.example.com/secret']),
        '*' => Http::response(SUB_ICS),
    ]);
    expect(fn () => IcsFetcher::get('webcal://cal.example.com/x.ics'))->toThrow(RuntimeException::class, 'blocked');
});

it('adds a calendar, syncs it, and shows its events read-only in the calendar', function () {
    [, $users] = calendarFamily(['calendar']);
    $user = $users[0];
    Http::fake(['cal.example.com/*' => Http::response(SUB_ICS)]);

    actingAs($user)->post('/m/calendar/subscriptions', [
        'name' => 'School', 'url' => 'webcal://cal.example.com/school.ics', 'color' => 'blue', 'timezone' => 'America/New_York',
    ])->assertSessionHasNoErrors();

    $sub = CalendarSubscription::withoutGlobalScopes()->firstOrFail();
    expect($sub->url)->toBe('https://cal.example.com/school.ics') // webcal → https
        ->and($sub->getRawOriginal('url'))->not->toContain('example.com'); // stored encrypted

    $items = collect(CalendarSources::collect('2026-10-01', '2026-10-31', $user->id))->where('kind', 'external');
    expect($items->pluck('title')->unique()->sort()->values()->all())->toContain('School trip', 'Standup')
        ->and($items->first()['editable'])->toBeFalse()
        ->and($items->first()['color'])->toBe('blue');

    $trip = CalendarSubscriptionEvent::where('title', 'School trip')->firstOrFail();
    actingAs($user)->get("/m/calendar/external/{$trip->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('calendar/external')->where('event.location', 'Museum')->where('calendar.name', 'School'));
});

it('shows a form error and keeps nothing when the URL is not a calendar', function () {
    [, $users] = calendarFamily(['calendar']);
    $user = $users[0];
    Http::fake(['*' => Http::response('<html>nope</html>')]);

    actingAs($user)->post('/m/calendar/subscriptions', ['name' => 'X', 'url' => 'https://cal.example.com/x', 'timezone' => 'UTC'])
        ->assertSessionHasErrors('url');
    expect(CalendarSubscription::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps a private calendar to its owner and shows a shared one to the family, owner-only to change', function () {
    [, [$owner, $other]] = calendarFamily(['calendar']);
    Http::fake(['*' => Http::response(SUB_ICS)]);

    actingAs($owner)->post('/m/calendar/subscriptions', ['name' => 'Work', 'url' => 'https://cal.example.com/w.ics', 'timezone' => 'UTC']);
    $sub = CalendarSubscription::withoutGlobalScopes()->firstOrFail();
    $externals = fn (User $u) => collect(CalendarSources::collect('2026-10-01', '2026-10-31', $u->id))->where('kind', 'external');

    expect($externals($other))->toBeEmpty();
    $event = CalendarSubscriptionEvent::firstOrFail();
    actingAs($other)->get("/m/calendar/external/{$event->id}")->assertNotFound();

    actingAs($other)->patch("/m/calendar/subscriptions/{$sub->id}", ['shared' => true])->assertForbidden();
    actingAs($owner)->patch("/m/calendar/subscriptions/{$sub->id}", ['shared' => true])->assertRedirect();
    expect($externals($other))->not->toBeEmpty();

    actingAs($owner)->patch("/m/calendar/subscriptions/{$sub->id}", ['enabled' => false]);
    expect($externals($owner))->toBeEmpty();

    actingAs($other)->delete("/m/calendar/subscriptions/{$sub->id}")->assertForbidden();
    actingAs($owner)->delete("/m/calendar/subscriptions/{$sub->id}")->assertRedirect();
    expect(CalendarSubscriptionEvent::count())->toBe(0);
});

it('keeps the last good events when a refresh fails', function () {
    [, $users] = calendarFamily(['calendar']);
    $user = $users[0];
    Http::fakeSequence()->push(SUB_ICS)->push('', 500);

    actingAs($user)->post('/m/calendar/subscriptions', ['name' => 'School', 'url' => 'https://cal.example.com/s.ics', 'timezone' => 'UTC']);
    $count = CalendarSubscriptionEvent::count();

    test()->artisan('calendar:sync-subscriptions')->assertExitCode(0);
    expect(CalendarSubscriptionEvent::count())->toBe($count)
        ->and(CalendarSubscription::withoutGlobalScopes()->first()->error)->toBe('unreachable');
});
