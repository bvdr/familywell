<?php

use Illuminate\Support\Facades\Notification;
use Modules\Calendar\CalendarEvent;
use Modules\Notifications\NotificationCategories;
use Modules\Notifications\PushAlert;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// "Calendar changes" (the onboarding's notifications toggle): adding, moving or renaming an event pushes the
// people who can see it, never the person who did it; a notes-only edit stays quiet.

it('registers calendar as a push category', function () {
    calendarFamily();

    expect(NotificationCategories::ids())->toContain('calendar');
});

it('pushes the family when an event is added, moved or renamed, not the actor', function () {
    Notification::fake();
    [, [$alice, $bob]] = calendarFamily();

    actingAs($alice)->post('/m/calendar/events', ['title' => 'Swim practice', 'start_date' => '2026-07-17', 'end_date' => '2026-07-17'])->assertSessionHasNoErrors();
    Notification::assertSentTo($bob, PushAlert::class);
    Notification::assertNotSentTo($alice, PushAlert::class);

    Notification::fake();
    $event = CalendarEvent::withoutGlobalScopes()->first();
    actingAs($alice)->patch("/m/calendar/events/{$event->id}", ['start_time' => '17:30', 'end_time' => '18:30'])->assertSessionHasNoErrors();
    Notification::assertSentTo($bob, PushAlert::class, fn ($n) => str_contains($n->body, 'moved'));

    Notification::fake();
    actingAs($alice)->patch("/m/calendar/events/{$event->id}", ['notes' => 'Bring goggles'])->assertSessionHasNoErrors();
    Notification::assertNothingSent();
});
