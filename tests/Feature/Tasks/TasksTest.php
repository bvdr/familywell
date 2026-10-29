<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\ImportantTodos\ImportantFlag;
use Modules\Notifications\PushAlert;
use Modules\RecurringTodos\RecurringRule;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;
use NotificationChannels\WebPush\WebPushChannel;

use function Pest\Laravel\actingAs;

function tasksFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('tasks', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/tasks')->assertNotFound();
});

it('creates a list with an owner share and lists it', function () {
    [, $users] = tasksFamily();

    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Move house', 'color' => 'blue', 'icon' => 'home'])->assertRedirect();

    $list = TaskList::withoutGlobalScopes()->first();
    expect($list->name)->toBe('Move house')
        ->and($list->shares()->where('role', 'owner')->exists())->toBeTrue();

    actingAs($users[0])->get('/m/tasks')->assertOk();
});

it('adds, toggles, and deletes a task', function () {
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Vacuum'])->assertRedirect();
    $task = Task::withoutGlobalScopes()->first();
    expect($task->done)->toBeFalse()->and($task->task_list_id)->toBe($list->id);

    actingAs($users[0])->patch("/m/tasks/tasks/{$task->id}")->assertRedirect(); // empty body toggles
    expect($task->fresh()->done)->toBeTrue();

    actingAs($users[0])->delete("/m/tasks/tasks/{$task->id}")->assertRedirect();
    // Deletes go to the 30-day trash (soft delete), not straight out of the database.
    expect(Task::withoutGlobalScope('family')->count())->toBe(0);
    $this->assertSoftDeleted($task);
});

it('adds a sub-task but rejects a second level of nesting', function () {
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Trip']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Pack']);
    $parent = Task::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Passports', 'parent_id' => $parent->id])->assertRedirect();
    $sub = Task::withoutGlobalScopes()->where('parent_id', $parent->id)->first();
    expect($sub)->not->toBeNull();

    // A sub-task may not itself be a parent (one level only).
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Nope', 'parent_id' => $sub->id])->assertStatus(422);
});

it('duplicates a list with its tasks and sub-tasks, reset to not-done', function () {
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Reno']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Demo wall']);
    $parent = Task::withoutGlobalScopes()->first();
    actingAs($users[0])->patch("/m/tasks/tasks/{$parent->id}"); // mark done
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Skip bin', 'parent_id' => $parent->id]);

    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/duplicate")->assertRedirect();

    $copy = TaskList::withoutGlobalScopes()->where('name', 'Reno (copy)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->tasks()->count())->toBe(2)
        ->and($copy->tasks()->where('done', true)->count())->toBe(0);
});

it('reorders and nests tasks, rejecting a second level', function () {
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'A']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'B']);
    $a = Task::withoutGlobalScopes()->where('title', 'A')->first();
    $b = Task::withoutGlobalScopes()->where('title', 'B')->first();

    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks/reorder", [
        'order' => [['id' => $a->id, 'parent_id' => null], ['id' => $b->id, 'parent_id' => $a->id]],
    ])->assertRedirect();
    expect($b->fresh()->parent_id)->toBe($a->id);

    // A may not nest under B, because B is itself nested (one level only).
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks/reorder", [
        'order' => [['id' => $b->id, 'parent_id' => $a->id], ['id' => $a->id, 'parent_id' => $b->id]],
    ])->assertStatus(422);
});

it('pushes everyone with access when a task is added, but not the actor', function () {
    Notification::fake();
    [, $users] = tasksFamily();
    [$alice, $bob] = $users;
    actingAs($alice)->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($alice)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Vacuum']);

    Notification::assertSentTo($bob, PushAlert::class);
    Notification::assertNotSentTo($alice, PushAlert::class);
});

it('mutes the push for a muted category but still logs it in-app', function () {
    Notification::fake();
    [, $users] = tasksFamily();
    [$alice, $bob] = $users;
    $bob->update(['notification_prefs' => ['tasks' => false]]);
    actingAs($alice)->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($alice)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Vacuum']);

    // Still delivered to Bob, but only on the in-app log channel, no web push.
    Notification::assertSentTo($bob, PushAlert::class, function ($notification, $channels) {
        return in_array('database', $channels, true) && ! in_array(WebPushChannel::class, $channels, true);
    });
});

it('hides a private list from a member who was not shared', function () {
    [, $users] = tasksFamily();
    [$alice, $bob] = $users;
    actingAs($alice)->post('/m/tasks/lists', ['name' => 'Secret', 'visibility' => 'private']);
    $list = TaskList::withoutGlobalScopes()->first();

    $access = app(ShareAccess::class);
    expect($access->canView($alice, $list))->toBeTrue()
        ->and($access->canView($bob, $list))->toBeFalse();
});

it('fills Tomorrow on a Sunday, when tomorrow is already next week', function () {
    $this->travelTo(Carbon::parse('2026-08-09 09:00:00')); // Sunday
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Monday', 'due_date' => '2026-08-10']);

    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p->where('tomorrow.0.title', 'Monday')->has('later', 0));
    $this->travelBack();
});

it('shows the next 3 days after Tomorrow on a Friday, into next week', function () {
    // It used to stop at Sunday, leaving a one-day strip on Fridays (2026-10-30).
    $this->travelTo(Carbon::parse('2026-10-30 09:00:00')); // Friday
    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Monday', 'due_date' => '2026-11-02']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Wednesday', 'due_date' => '2026-11-04']);

    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('stripDays', ['2026-11-01', '2026-11-02', '2026-11-03'])
        ->has('later', 1)
        ->where('later.0.title', 'Monday'));
    $this->travelBack();
});

it('counts open undated to-dos for Home\'s Anytime row, skipping done, dated and private ones', function () {
    [$family, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();
    foreach (['Clean gutters', 'Fix bike light'] as $title) {
        actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => $title]);
    }
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Dated', 'due_date' => now()->toDateString()]);
    Task::withoutGlobalScopes()->where('title', 'Fix bike light')->first()->update(['done' => true]);
    actingAs($users[1])->post('/m/tasks/lists', ['name' => 'Mine']);
    $mine = TaskList::withoutGlobalScopes()->where('name', 'Mine')->first();
    $mine->setVisibility('private');
    actingAs($users[1])->post("/m/tasks/lists/{$mine->id}/tasks", ['title' => 'Secret']);

    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('widgets.tasks', ['undated' => 1, 'titles' => ['Clean gutters']]));
});

it('buckets dated tasks into the dashboard Today, Tomorrow and next-3-days sections', function () {
    // Freeze on a Wednesday so the window (Today, Tomorrow, then Fri-Sun) is fixed whenever the suite runs.
    $this->travelTo(Carbon::parse('2026-08-05 09:00:00'));

    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    // Overdue + due-today land in Today; Thursday in Tomorrow; Friday in the strip; next Wednesday is past it.
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Overdue', 'due_date' => '2026-08-03']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Due today', 'due_date' => '2026-08-05']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Thursday', 'due_date' => '2026-08-06']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Friday', 'due_date' => '2026-08-07']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Next week', 'due_date' => '2026-08-12']);

    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p
        ->has('today', 2)
        ->has('tomorrow', 1)
        ->where('tomorrow.0.title', 'Thursday')
        ->has('later', 1)
        ->where('later.0.title', 'Friday'));

    $this->travelBack();
});

it('only offers members with access as assignees on a private list', function () {
    [, $users] = tasksFamily(); // owner + one other member
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Secret', 'visibility' => 'private']);
    $list = TaskList::withoutGlobalScopes()->latest('id')->first();

    // The non-shared member can't reach the list, so they must not be assignable.
    actingAs($users[0])->get("/m/tasks/lists/{$list->id}")
        ->assertInertia(fn ($p) => $p->has('members', 1)->where('members.0.id', $users[0]->id));
});

it('deletes a task carrying the important flag + a recurring rule and cleans up both, landing on a valid page', function () {
    // Regression for the branded 404 on deleting an extended task: the extension rows must cascade
    // away with the task (no orphans), the delete must redirect (not 404), and the list it lands on
    // must still render. (The client-side companion fix closes the edit drawer on delete so its
    // on-close flush never PATCHes the now-gone task — see resources/js/components/tasks/task-tree.tsx.)
    [$family, $users] = tasksFamily();
    $reg = app(ModuleRegistry::class);
    $reg->enable('important_todos', $family->id);
    $reg->enable('recurring_todos', $family->id);

    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Mow the lawn', 'due_date' => '2026-09-01']);
    $task = Task::withoutGlobalScopes()->first();

    // Mark it important + weekly through the shared task endpoint (the module save hooks persist both).
    actingAs($users[0])->patch("/m/tasks/tasks/{$task->id}", ['important' => true, 'recurrence' => 'weekly'])->assertRedirect();
    expect(ImportantFlag::withoutGlobalScopes()->where('task_id', $task->id)->exists())->toBeTrue()
        ->and(RecurringRule::withoutGlobalScopes()->where('task_id', $task->id)->exists())->toBeTrue();

    actingAs($users[0])
        ->from("/m/tasks/lists/{$list->id}")
        ->delete("/m/tasks/tasks/{$task->id}")
        ->assertRedirect(); // not a 404

    // The task goes to the trash; its extension rows stay attached so a restore brings them back...
    expect(Task::withoutGlobalScope('family')->count())->toBe(0)
        ->and(ImportantFlag::withoutGlobalScopes()->count())->toBe(1)
        ->and(RecurringRule::withoutGlobalScopes()->count())->toBe(1);
    $this->assertSoftDeleted($task);

    // ...and once the trash is purged the task is gone and the FK cascade takes both rows with it.
    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertSuccessful();
    expect(Task::withoutGlobalScopes()->withTrashed()->count())->toBe(0)
        ->and(ImportantFlag::withoutGlobalScopes()->count())->toBe(0)
        ->and(RecurringRule::withoutGlobalScopes()->count())->toBe(0);
    $this->travelBack();

    // The page the delete redirects back to still resolves (no branded "Nothing here").
    actingAs($users[0])->get("/m/tasks/lists/{$list->id}")->assertOk();
});
