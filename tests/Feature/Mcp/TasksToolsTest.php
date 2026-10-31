<?php

use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

function tasksToken(int $members = 2): array
{
    [$family, $users] = mcpFamily(['tasks'], $members);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write'])['access_token'];

    return [$family, $users, $token];
}

it('creates a list, adds tasks and a sub-task, and reads them back', function () {
    [, $users, $token] = tasksToken();

    [$err, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Weekend']);
    expect($err)->toBeFalse()->and($list['name'])->toBe('Weekend')->and($list['url'])->toEndWith("/m/tasks/lists/{$list['id']}");

    [, $top] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Garden', 'due_date' => '2026-10-04', 'assigned_to' => $users[1]->id]);
    [, $sub] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Rake leaves', 'parent_id' => $top['id']]);
    expect($top['assigned_to']['id'])->toBe($users[1]->id)->and($sub['parent_id'])->toBe($top['id']);

    // One level only.
    [$err, $msg] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Too deep', 'parent_id' => $sub['id']]);
    expect($err)->toBeTrue()->and($msg)->toContain('one level');

    [, $got] = mcpTool($token, 'tasks_get_list', ['list_id' => $list['id']]);
    expect($got['tasks'])->toHaveCount(1)->and($got['tasks'][0]['subtasks'][0]['title'])->toBe('Rake leaves');

    [, $lists] = mcpTool($token, 'tasks_list_todo_lists');
    expect(collect($lists['lists'])->firstWhere('id', $list['id'])['open_count'])->toBe(2);

    [, $found] = mcpTool($token, 'tasks_search', ['query' => 'rake']);
    expect(collect($found['tasks'])->pluck('title')->all())->toBe(['Rake leaves']);
});

it('rejects an assignee outside the family', function () {
    [, , $token] = tasksToken();
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'L']);
    $stranger = User::factory()->create();

    [$err, $msg] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'X', 'assigned_to' => $stranger->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('assigned to');
});

it('updates a list and a task', function () {
    [, , $token] = tasksToken();
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Old']);
    [, $task] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Call plumber', 'note' => 'after 5']);

    [, $l] = mcpTool($token, 'tasks_update_list', ['list_id' => $list['id'], 'name' => 'New', 'archived' => true]);
    expect($l['name'])->toBe('New')->and($l['archived'])->toBeTrue();

    [, $t] = mcpTool($token, 'tasks_update_task', ['task_id' => $task['id'], 'done' => true, 'note' => null]);
    expect($t['done'])->toBeTrue()->and($t['note'])->toBeNull()->and($t['title'])->toBe('Call plumber');
});

it('deletes to the trash, lists the trash, and restores', function () {
    [, , $token] = tasksToken();
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Chores']);
    [, $task] = mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Dishes']);

    [, $del] = mcpTool($token, 'tasks_delete_list', ['list_id' => $list['id']]);
    expect($del['deleted'])->toBeTrue()->and($del['purged_on'])->toBe(now()->addDays(30)->toDateString());

    [, $trash] = mcpTool($token, 'tasks_list_trash');
    expect(collect($trash['deleted'])->map(fn ($d) => $d['type'].':'.$d['id'])->sort()->values()->all())
        ->toBe(['list:'.$list['id'], 'task:'.$task['id']]);

    // Restoring just the task brings its list back too.
    [, $res] = mcpTool($token, 'tasks_restore', ['type' => 'task', 'id' => $task['id']]);
    expect($res['restored'])->toBeTrue()
        ->and(TaskList::withoutGlobalScope('family')->find($list['id']))->not->toBeNull()
        ->and(Task::withoutGlobalScope('family')->find($task['id']))->not->toBeNull();

    [$err] = mcpTool($token, 'tasks_restore', ['type' => 'task', 'id' => $task['id']]);
    expect($err)->toBeTrue(); // not in the trash any more

    [, $d] = mcpTool($token, 'tasks_delete_task', ['task_id' => $task['id']]);
    expect($d['type'])->toBe('task');
    $this->assertSoftDeleted('tasks_tasks', ['id' => $task['id']]);
});

it('cannot see or touch another member\'s private list', function () {
    [$family, $users, $token] = tasksToken();
    CurrentFamily::set($family->id);
    $private = TaskList::createFor($users[1], ['name' => 'Secret'], 'private');
    $task = $private->addTask($users[1], ['title' => 'Hidden']);

    [, $lists] = mcpTool($token, 'tasks_list_todo_lists');
    expect(collect($lists['lists'])->pluck('name'))->not->toContain('Secret');
    [, $found] = mcpTool($token, 'tasks_search', ['query' => 'Hidden']);
    expect($found['tasks'])->toBe([]);

    expect(mcpTool($token, 'tasks_get_list', ['list_id' => $private->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'tasks_create_task', ['list_id' => $private->id, 'title' => 'x'])[0])->toBeTrue()
        ->and(mcpTool($token, 'tasks_update_task', ['task_id' => $task->id, 'done' => true])[0])->toBeTrue()
        ->and(mcpTool($token, 'tasks_delete_task', ['task_id' => $task->id])[0])->toBeTrue();
    expect($task->fresh()->done)->toBeFalse()->and($task->fresh()->trashed())->toBeFalse();
});

it('only lets the creator delete a list', function () {
    [$family, $users, $token] = tasksToken();
    CurrentFamily::set($family->id);
    $theirs = TaskList::createFor($users[1], ['name' => 'Theirs']);

    [$err, $msg] = mcpTool($token, 'tasks_delete_list', ['list_id' => $theirs->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('created this list');
});

it('puts due tasks in get_today, only for a token that can read tasks', function () {
    [$family, $users, $token] = tasksToken();
    [, $list] = mcpTool($token, 'tasks_create_list', ['name' => 'Today']);
    mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Pay rent', 'due_date' => now()->toDateString()]);
    mcpTool($token, 'tasks_create_task', ['list_id' => $list['id'], 'title' => 'Old one', 'due_date' => now()->subDays(3)->toDateString()]);

    [, $today] = mcpTool($token, 'get_today');
    expect(collect($today['due'])->pluck('title')->all())->toBe(['Old one', 'Pay rent'])
        ->and(collect($today['due'])->firstWhere('title', 'Old one')['overdue'])->toBeTrue();

    $blind = mcpConnect($users[0], $family->id)['access_token']; // no module access at all
    [, $nothing] = mcpTool($blind, 'get_today');
    expect($nothing['due'])->toBe([]);
});
