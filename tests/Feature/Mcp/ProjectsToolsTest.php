<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Modules\Projects\Project;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\Task;

function projectsToken(string $access = 'write'): array
{
    [$family, $users] = mcpFamily(['projects', 'tasks', 'shopping'], 2);
    $token = mcpConnect($users[0], $family->id, ['projects' => $access])['access_token'];

    return [$family, $users, $token];
}

it('creates a project, adds tasks and a sub-task, and reads it back', function () {
    [$family, $users, $token] = projectsToken();

    [$err, $p] = mcpTool($token, 'projects_create', ['name' => 'Kitchen', 'target_start' => '2026-11-01']);
    expect($err)->toBeFalse()->and($p['status'])->toBe('planning')->and($p['url'])->toEndWith("/m/projects/{$p['id']}");
    expect(Project::find($p['id'])->shares()->where('user_id', $users[0]->id)->value('role'))->toBe('owner');

    [, $top] = mcpTool($token, 'projects_add_task', ['project_id' => $p['id'], 'title' => 'Pick tiles', 'assigned_to' => $users[1]->id]);
    [, $sub] = mcpTool($token, 'projects_add_task', ['project_id' => $p['id'], 'title' => 'Visit shop', 'parent_id' => $top['id']]);
    expect($top['assigned_to']['id'])->toBe($users[1]->id)->and($sub['parent_id'])->toBe($top['id']);
    expect(Task::find($top['id'])->task_list_id)->toBeNull(); // a listless tasks-module task, like the web UI

    [$err, $msg] = mcpTool($token, 'projects_add_task', ['project_id' => $p['id'], 'title' => 'Too deep', 'parent_id' => $sub['id']]);
    expect($err)->toBeTrue()->and($msg)->toContain('one level');

    CurrentFamily::set($family->id);
    $list = ShoppingList::create(['name' => 'Hardware', 'created_by' => $users[0]->id]);
    Project::find($p['id'])->links()->create(['linkable_type' => 'shopping_list', 'linkable_id' => $list->id]);
    Project::find($p['id'])->dependencies()->create(['name' => 'Plumber', 'status' => 'pending', 'created_by' => $users[0]->id]);

    [, $got] = mcpTool($token, 'projects_get', ['project_id' => $p['id']]);
    expect($got['tasks'])->toHaveCount(1)
        ->and($got['tasks'][0]['subtasks'][0]['title'])->toBe('Visit shop')
        ->and($got['dependencies'][0]['name'])->toBe('Plumber')
        ->and($got['shopping_lists'][0]['name'])->toBe('Hardware')
        ->and($got['tasks_total'])->toBe(1);

    [, $all] = mcpTool($token, 'projects_list');
    expect(collect($all['projects'])->pluck('name')->all())->toBe(['Kitchen']);
});

it('updates a project and a task, and deletes a task', function () {
    [, $users, $token] = projectsToken();
    [, $p] = mcpTool($token, 'projects_create', ['name' => 'Garage']);
    [, $t] = mcpTool($token, 'projects_add_task', ['project_id' => $p['id'], 'title' => 'Clear shelves']);

    [, $u] = mcpTool($token, 'projects_update', ['project_id' => $p['id'], 'status' => 'blocked', 'waiting_on' => 'Skip hire', 'progress' => 40]);
    expect($u['status'])->toBe('blocked')->and($u['waiting_on'])->toBe('Skip hire')->and($u['progress'])->toBe(40);

    [, $done] = mcpTool($token, 'projects_update_task', ['task_id' => $t['id'], 'done' => true, 'assigned_to' => $users[1]->id]);
    expect($done['done'])->toBeTrue()->and($done['assigned_to']['id'])->toBe($users[1]->id);
    [, $list] = mcpTool($token, 'projects_list', ['status' => 'blocked']);
    expect($list['projects'][0]['tasks_done'])->toBe(1);

    [$err, $msg] = mcpTool($token, 'projects_update_task', ['task_id' => $t['id'], 'assigned_to' => User::factory()->create()->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('assigned to');

    [, $del] = mcpTool($token, 'projects_delete_task', ['task_id' => $t['id']]);
    expect($del['deleted'])->toBeTrue();
    $this->assertSoftDeleted('tasks_tasks', ['id' => $t['id']]);
    $this->assertDatabaseMissing('projects_links', ['linkable_type' => 'task', 'linkable_id' => $t['id']]);
});

it('gives a read-only token only the read tools', function () {
    [, , $token] = projectsToken('read');

    $names = collect(mcpToolNames($token))->filter(fn ($n) => str_starts_with($n, 'projects_'))->values()->all();
    expect($names)->toBe(['projects_get', 'projects_list']);
    expect(mcpTool($token, 'projects_create', ['name' => 'Nope'])[0])->toBeTrue();
});

it('cannot see or touch another member\'s private project', function () {
    [$family, $users, $token] = projectsToken();
    CurrentFamily::set($family->id);
    $private = Project::createFor($users[1], ['name' => 'Surprise party']);
    $private->setVisibility('private');
    $task = $private->addTask($users[1], ['title' => 'Book venue']);

    [, $all] = mcpTool($token, 'projects_list');
    expect(collect($all['projects'])->pluck('name'))->not->toContain('Surprise party');

    expect(mcpTool($token, 'projects_get', ['project_id' => $private->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'projects_update', ['project_id' => $private->id, 'name' => 'x'])[0])->toBeTrue()
        ->and(mcpTool($token, 'projects_add_task', ['project_id' => $private->id, 'title' => 'x'])[0])->toBeTrue()
        ->and(mcpTool($token, 'projects_update_task', ['task_id' => $task->id, 'done' => true])[0])->toBeTrue()
        ->and(mcpTool($token, 'projects_delete_task', ['task_id' => $task->id])[0])->toBeTrue();
    expect($private->fresh()->name)->toBe('Surprise party')
        ->and($task->fresh()->done)->toBeFalse()->and($task->fresh()->trashed())->toBeFalse();
});

it('keeps other families\' projects out of reach', function () {
    [, , $token] = projectsToken();
    $stranger = User::factory()->create();
    $other = Family::factory()->create(['owner_id' => $stranger->id]);
    $other->members()->attach($stranger, ['role' => 'admin']);
    CurrentFamily::set($other->id);
    $theirs = Project::createFor($stranger, ['name' => 'Their project']);
    $task = $theirs->addTask($stranger, ['title' => 'Theirs']);

    [, $all] = mcpTool($token, 'projects_list');
    expect($all['projects'])->toBe([]);
    expect(mcpTool($token, 'projects_get', ['project_id' => $theirs->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'projects_update_task', ['task_id' => $task->id, 'done' => true])[0])->toBeTrue();
    expect(Task::withoutGlobalScope('family')->find($task->id)->done)->toBeFalse();
});
