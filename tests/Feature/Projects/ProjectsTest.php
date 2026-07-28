<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Projects\Project;
use Modules\Projects\ProjectTask;

use function Pest\Laravel\actingAs;

function projectsFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('projects', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/projects')->assertNotFound();
});

it('creates a project with an owner share and lists it', function () {
    [, $users] = projectsFamily();

    actingAs($users[0])->post('/m/projects', ['name' => 'Backyard Paving', 'status' => 'in_progress', 'progress' => 45])->assertRedirect();

    $project = Project::withoutGlobalScopes()->first();
    expect($project->name)->toBe('Backyard Paving')
        ->and($project->status)->toBe('in_progress')
        ->and($project->progress)->toBe(45)
        ->and($project->owner_id)->toBe($users[0]->id)
        ->and($project->shares()->where('role', 'owner')->exists())->toBeTrue();

    actingAs($users[0])->get('/m/projects')->assertOk();
});

it('adds, toggles, and deletes a task', function () {
    [, $users] = projectsFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/projects/{$project->id}/tasks", ['name' => 'Measure area', 'assigned_to' => $users[0]->id])->assertRedirect();
    $task = ProjectTask::withoutGlobalScopes()->first();
    expect($task->done)->toBeFalse();

    actingAs($users[0])->patch("/m/projects/tasks/{$task->id}")->assertRedirect(); // empty body toggles
    expect($task->fresh()->done)->toBeTrue();

    actingAs($users[0])->delete("/m/projects/tasks/{$task->id}")->assertRedirect();
    expect(ProjectTask::withoutGlobalScopes()->count())->toBe(0);
});

it('adds a dependency and cycles its status', function () {
    [, $users] = projectsFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/projects/{$project->id}/dependencies", ['name' => 'Council permit'])->assertRedirect();
    $dep = $project->dependencies()->first();
    expect($dep->status)->toBe('pending');

    actingAs($users[0])->patch("/m/projects/dependencies/{$dep->id}", ['status' => 'done'])->assertRedirect();
    expect($dep->fresh()->status)->toBe('done');
});

it('links and unlinks a shopping list', function () {
    [$family, $users] = projectsFamily();
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Materials']);
    $list = \Modules\Shopping\ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/projects/{$project->id}/lists", ['list_id' => $list->id])->assertRedirect();
    expect($project->links()->where('linkable_id', $list->id)->exists())->toBeTrue();

    actingAs($users[0])->delete("/m/projects/{$project->id}/lists/{$list->id}")->assertRedirect();
    expect($project->links()->count())->toBe(0);
});

it('hides a private project from a member who was not shared', function () {
    [, $users] = projectsFamily();
    [$alice, $bob] = $users;
    actingAs($alice)->post('/m/projects', ['name' => 'Surprise']);
    $project = Project::withoutGlobalScopes()->first();
    actingAs($alice)->patch("/m/projects/{$project->id}", ['visibility' => 'private'])->assertRedirect();

    $access = app(\App\Support\Sharing\ShareAccess::class);
    expect($access->canView($alice, $project))->toBeTrue()
        ->and($access->canView($bob, $project))->toBeFalse();
});
