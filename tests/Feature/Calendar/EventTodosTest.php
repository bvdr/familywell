<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Calendar\CalendarEvent;
use Modules\Tasks\Task;

use function Pest\Laravel\actingAs;

// calendarFamily() and trip() live in EventsTest.php; Pest only loads files it runs, so redefine guards.
require_once __DIR__.'/helpers.php';

it('adds, nests, ticks and deletes to-dos on an event', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);

    actingAs($users[0])->post("/m/calendar/events/{$e->id}/tasks", ['title' => 'Pack'])->assertRedirect();
    $pack = Task::where('title', 'Pack')->firstOrFail();
    expect($pack->task_list_id)->toBeNull()->and($e->taskIds())->toBe([$pack->id]);

    actingAs($users[0])->post("/m/calendar/events/{$e->id}/tasks", ['title' => 'Passports', 'parent_id' => $pack->id])->assertRedirect();
    $pass = Task::where('title', 'Passports')->firstOrFail();
    expect($pass->parent_id)->toBe($pack->id)->and($e->taskIds())->toBe([$pack->id]); // sub-tasks are not linked

    actingAs($users[0])->patch("/m/calendar/tasks/{$pass->id}", [])->assertRedirect(); // empty body toggles
    expect($pass->fresh()->done)->toBeTrue();

    actingAs($users[0])->delete("/m/calendar/tasks/{$pack->id}")->assertRedirect();
    $this->assertSoftDeleted('tasks_tasks', ['id' => $pack->id]);
    expect($e->fresh()->taskIds())->toBe([$pack->id]); // link kept so a restore puts it back on the event
});

it('trashes and restores to-dos with their event', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    $t = $e->addTask($users[0], ['title' => 'Book hotel']);
    $e->addTask($users[0], ['title' => 'Sub', 'parent_id' => $t->id]);

    $e->delete();
    expect(Task::where('title', 'Book hotel')->exists())->toBeFalse()->and(Task::where('title', 'Sub')->exists())->toBeFalse();

    CalendarEvent::onlyTrashed()->find($e->id)->restore();
    expect(Task::where('title', 'Book hotel')->exists())->toBeTrue()->and(Task::where('title', 'Sub')->exists())->toBeTrue();
});

it('refuses to-do edits from someone who cannot see the event', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    $e->setVisibility('private');
    $t = $e->addTask($users[0], ['title' => 'Secret']);
    actingAs($users[1])->patch("/m/calendar/tasks/{$t->id}", ['title' => 'x'])->assertForbidden();
    actingAs($users[1])->post("/m/calendar/events/{$e->id}/tasks", ['title' => 'x'])->assertForbidden();
});

it('reorders and re-nests to-dos', function () {
    [, $users] = calendarFamily();
    $e = trip($users[0]);
    $a = $e->addTask($users[0], ['title' => 'A']);
    $b = $e->addTask($users[0], ['title' => 'B']);
    actingAs($users[0])->post("/m/calendar/events/{$e->id}/tasks/reorder", ['order' => [
        ['id' => $b->id, 'parent_id' => null], ['id' => $a->id, 'parent_id' => $b->id],
    ]])->assertRedirect();
    expect($a->fresh()->parent_id)->toBe($b->id)->and($e->fresh()->taskIds())->toBe([$b->id]);
});

it('refuses an assignee from another family, without notifying them', function () {
    Notification::fake();
    [, $users] = calendarFamily();
    $stranger = User::factory()->create();
    $e = trip($users[0]);
    actingAs($users[0])->post("/m/calendar/events/{$e->id}/tasks", ['title' => 'Pack', 'assigned_to' => $stranger->id])
        ->assertSessionHasErrors('assigned_to');
    $t = $e->addTask($users[0], ['title' => 'Book hotel']);
    actingAs($users[0])->patch("/m/calendar/tasks/{$t->id}", ['assigned_to' => $stranger->id])->assertSessionHasErrors('assigned_to');

    expect(Task::where('title', 'Pack')->exists())->toBeFalse()->and($t->fresh()->assigned_to)->toBeNull();
    Notification::assertNothingSent();
});
