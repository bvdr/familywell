<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

// The Add sheet's "most used, last 30 days": family-wide counts per enabled module.

function addUsageFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('tasks', $family->id);

    return [$family, $users];
}

it('counts what the family added in the last 30 days, private lists included', function () {
    [$family, $users] = addUsageFamily();
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $list = TaskList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Old one']);
    Carbon::setTestNow(now()->addDays(31)); // the first add falls out of the window
    actingAs($users[0])->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Call Grandma']);

    // A sibling's add in their private list still counts (a number reveals nothing).
    actingAs($users[1])->post('/m/tasks/lists', ['name' => 'Mine']);
    $mine = TaskList::withoutGlobalScopes()->where('name', 'Mine')->first();
    $mine->setVisibility('private');
    actingAs($users[1])->post("/m/tasks/lists/{$mine->id}/tasks", ['title' => 'Secret gift']);

    actingAs($users[0])->getJson('/add/usage')->assertOk()
        ->assertExactJson(['tasks' => 2]);
});

it('leaves out modules the family has turned off, and needs a signed-in member', function () {
    [$family, $users] = addUsageFamily();
    app(ModuleRegistry::class)->disable('tasks', $family->id);

    actingAs($users[0])->getJson('/add/usage')->assertOk()->assertExactJson([]);
    auth()->logout();
    $this->getJson('/add/usage')->assertUnauthorized();
});

it('gives the More sheet the family name and each module\'s status line', function () {
    [$family, $users] = addUsageFamily();
    actingAs($users[0])->getJson('/nav/more')->assertOk()
        ->assertJsonPath('family', $family->name)
        ->assertJsonPath('summaries.tasks.key', 'dashboard.sum_tasks');
});
