<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Add\AddKinds;
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
        ->assertJsonPath('usage', ['tasks' => 2]);
});

it('leaves out modules the family has turned off, and needs a signed-in member', function () {
    [$family, $users] = addUsageFamily();
    app(ModuleRegistry::class)->disable('tasks', $family->id);

    actingAs($users[0])->getJson('/add/usage')->assertOk()->assertJsonPath('usage', []);
    auth()->logout();
    $this->getJson('/add/usage')->assertUnauthorized();
});

it('gives the More sheet the family name and each module\'s status line', function () {
    [$family, $users] = addUsageFamily();
    actingAs($users[0])->getJson('/nav/more')->assertOk()
        ->assertJsonPath('family', $family->name)
        ->assertJsonPath('summaries.tasks.key', 'dashboard.sum_tasks');
});

it('names the last thing this person added that they can still see', function () {
    [$family, $users] = addUsageFamily();
    // Theirs, in a list everyone sees: shown.
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Chores']);
    $chores = TaskList::withoutGlobalScopes()->where('name', 'Chores')->first();
    actingAs($users[0])->post("/m/tasks/lists/{$chores->id}/tasks", ['title' => 'Call Grandma']);
    // A sibling's add is never someone else's "last".
    actingAs($users[1])->post("/m/tasks/lists/{$chores->id}/tasks", ['title' => 'Fix the door']);

    actingAs($users[0])->getJson('/add/usage')->assertOk()->assertJsonPath('last.tasks', 'Call Grandma');
    actingAs($users[1])->getJson('/add/usage')->assertOk()->assertJsonPath('last.tasks', 'Fix the door');

    // users[1] adds into a list users[0] then makes private: the item is no longer theirs to see, so it can't be named.
    actingAs($users[0])->post('/m/tasks/lists', ['name' => 'Gifts']);
    $gifts = TaskList::withoutGlobalScopes()->where('name', 'Gifts')->first();
    actingAs($users[1])->post("/m/tasks/lists/{$gifts->id}/tasks", ['title' => 'Secret gift']);
    actingAs($users[1])->getJson('/add/usage')->assertJsonPath('last.tasks', 'Secret gift');
    $gifts->setVisibility('private');
    actingAs($users[1])->getJson('/add/usage')->assertJsonPath('last.tasks', 'Fix the door');
});

it('keeps one pinned shortcut per person, only for modules that are on', function () {
    [$family, $users] = addUsageFamily();
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('pins', []);

    actingAs($users[0])->putJson('/add/pins', ['pins' => ['tasks']])->assertOk();
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('pins', ['tasks']);
    actingAs($users[1])->getJson('/add/usage')->assertJsonPath('pins', []); // per person

    // One pin (design H2d), a module that's off or unknown is refused, unpinning is an empty list.
    actingAs($users[0])->putJson('/add/pins', ['pins' => ['tasks', 'shopping']])->assertUnprocessable();
    actingAs($users[0])->putJson('/add/pins', ['pins' => ['shopping']])->assertUnprocessable(); // shopping is off
    actingAs($users[0])->putJson('/add/pins', ['pins' => ['nope']])->assertUnprocessable();
    actingAs($users[0])->putJson('/add/pins', ['pins' => []])->assertOk();
    expect($users[0]->fresh()->add_pins)->toBe([]);

    // A pinned module turned off drops out of the sheet's pins (and comes back with it).
    actingAs($users[0])->putJson('/add/pins', ['pins' => ['tasks']])->assertOk();
    app(ModuleRegistry::class)->disable('tasks', $family->id);
    actingAs($users[0])->getJson('/add/usage')->assertJsonPath('pins', []);
});

it('runs every module\'s last-added query without error', function () {
    [$family, $users] = addUsageFamily();
    foreach (array_keys(app(ModuleRegistry::class)->discover()) as $id) {
        app(ModuleRegistry::class)->enable($id, $family->id);
    }
    // lastAdded() swallows a failing query (the sheet still opens), so call each module's query directly here.
    $lasts = (new ReflectionClass(AddKinds::class))->getStaticPropertyValue('lasts');
    expect(array_keys($lasts))->toContain('shopping', 'tasks', 'calendar', 'spending', 'projects', 'notes', 'recipes', 'vehicles', 'inventory');
    foreach ($lasts as $module => $last) {
        expect($last($users[0]))->toBeNull();
    }
});
