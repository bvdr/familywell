<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Projects\Project;
use Modules\Tasks\Task;

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

it('adds, toggles, and deletes a task (unified tasks-module tasks)', function () {
    [, $users] = projectsFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();

    // A project task is a listless tasks-module task, linked to the project.
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks", ['title' => 'Measure area', 'assigned_to' => $users[0]->id])->assertRedirect();
    $task = Task::withoutGlobalScopes()->first();
    expect($task->done)->toBeFalse()
        ->and($task->task_list_id)->toBeNull()
        ->and($project->links()->where(['linkable_type' => 'task', 'linkable_id' => $task->id])->exists())->toBeTrue();

    // Sub-task rides along under the same project link (no link of its own).
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks", ['title' => 'Grab tape', 'parent_id' => $task->id])->assertRedirect();
    expect(Task::withoutGlobalScopes()->where('parent_id', $task->id)->count())->toBe(1);

    actingAs($users[0])->patch("/m/projects/tasks/{$task->id}")->assertRedirect(); // empty body toggles
    expect($task->fresh()->done)->toBeTrue();

    actingAs($users[0])->delete("/m/projects/tasks/{$task->id}")->assertRedirect();
    expect(Task::withoutGlobalScopes()->count())->toBe(0) // parent delete cascades the sub-task
        ->and($project->links()->where('linkable_type', 'task')->count())->toBe(0);
});

it('reorders project tasks and reconciles links when nesting/un-nesting', function () {
    [, $users] = projectsFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks", ['title' => 'A']);
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks", ['title' => 'B']);
    $a = Task::withoutGlobalScopes()->where('title', 'A')->first();
    $b = Task::withoutGlobalScopes()->where('title', 'B')->first();
    expect($project->links()->where('linkable_type', 'task')->count())->toBe(2);

    // Nest B under A: B is now reached via A, so it loses its own project link.
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks/reorder", [
        'order' => [['id' => $a->id, 'parent_id' => null], ['id' => $b->id, 'parent_id' => $a->id]],
    ])->assertRedirect();
    expect($b->fresh()->parent_id)->toBe($a->id)
        ->and($project->links()->where(['linkable_type' => 'task', 'linkable_id' => $b->id])->exists())->toBeFalse()
        ->and($project->links()->where('linkable_type', 'task')->count())->toBe(1);

    // Un-nest B: it becomes top-level again and regains a link.
    actingAs($users[0])->post("/m/projects/{$project->id}/tasks/reorder", [
        'order' => [['id' => $a->id, 'parent_id' => null], ['id' => $b->id, 'parent_id' => null]],
    ])->assertRedirect();
    expect($project->links()->where('linkable_type', 'task')->count())->toBe(2);
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

    actingAs($users[0])->post("/m/projects/{$project->id}/lists", ['list_ids' => [$list->id]])->assertRedirect();
    expect($project->links()->where('linkable_id', $list->id)->exists())->toBeTrue();

    actingAs($users[0])->delete("/m/projects/{$project->id}/lists/{$list->id}")->assertRedirect();
    expect($project->links()->count())->toBe(0);
});

it('creates a new shopping list with items and links it', function () {
    [$family, $users] = projectsFamily();
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/projects/{$project->id}/lists/new", [
        'name' => 'Patio materials',
        'items' => [['name' => 'Paver sealer', 'quantity' => '2 tins'], ['name' => 'Landscape fabric']],
    ])->assertRedirect();

    $list = \Modules\Shopping\ShoppingList::withoutGlobalScopes()->where('name', 'Patio materials')->first();
    expect($list)->not->toBeNull()
        ->and($list->items()->count())->toBe(2)
        ->and($project->links()->where('linkable_id', $list->id)->exists())->toBeTrue();
});

it('uploads, serves, and deletes a project cover photo', function () {
    Storage::fake('r2');
    [, $users] = projectsFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();

    // fake()->image() needs GD (absent in CI); a fake file with an image mime satisfies the `image` rule.
    actingAs($users[0])->post("/m/projects/{$project->id}/cover", [
        'photo' => UploadedFile::fake()->create('cover.jpg', 200, 'image/jpeg'),
        'photo_thumb' => UploadedFile::fake()->create('cover.thumb.jpg', 20, 'image/jpeg'),
    ])->assertRedirect();
    $project->refresh();
    expect($project->cover_path)->not->toBeNull();
    Storage::disk('r2')->assertExists($project->cover_path);
    Storage::disk('r2')->assertExists($project->cover_path.'.thumb.jpg'); // the small variant is stored too

    actingAs($users[0])->get("/m/projects/{$project->id}/cover")->assertOk();
    actingAs($users[0])->get("/m/projects/{$project->id}/cover?v=thumb")->assertOk(); // serves the thumb variant

    actingAs($users[0])->delete("/m/projects/{$project->id}/cover")->assertRedirect();
    expect($project->fresh()->cover_path)->toBeNull();
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
