<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

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
    expect(Task::withoutGlobalScopes()->count())->toBe(0);
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
    Illuminate\Support\Facades\Notification::fake();
    [, $users] = tasksFamily();
    [$alice, $bob] = $users;
    actingAs($alice)->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($alice)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Vacuum']);

    Illuminate\Support\Facades\Notification::assertSentTo($bob, App\Notifications\PushAlert::class);
    Illuminate\Support\Facades\Notification::assertNotSentTo($alice, App\Notifications\PushAlert::class);
});

it('respects a muted category', function () {
    Illuminate\Support\Facades\Notification::fake();
    [, $users] = tasksFamily();
    [$alice, $bob] = $users;
    $bob->update(['notification_prefs' => ['tasks' => false]]);
    actingAs($alice)->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    actingAs($alice)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Vacuum']);

    Illuminate\Support\Facades\Notification::assertNotSentTo($bob, App\Notifications\PushAlert::class);
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

it('buckets dated tasks into the dashboard Today and This Week sections', function () {
    // Freeze mid-week (a Wednesday) so "this week" is unambiguous regardless of when the suite runs.
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-08-05 09:00:00'));

    [, $users] = tasksFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();

    // Overdue + due-today land in Today; later-this-week lands in This Week; next week is excluded.
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Overdue', 'due_date' => '2026-08-03']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Due today', 'due_date' => '2026-08-05']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Friday', 'due_date' => '2026-08-07']);
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Next week', 'due_date' => '2026-08-12']);

    actingAs($users[0])->get('/dashboard')->assertInertia(fn ($p) => $p
        ->has('today', 2)
        ->has('thisWeek', 1)
        ->where('thisWeek.0.title', 'Friday'));

    $this->travelBack();
});
