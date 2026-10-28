<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingList;

use function Pest\Laravel\actingAs;

// A family with shopping + notes installed, enabled, and active in session.
function searchFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    app(ModuleRegistry::class)->enable('notes', $family->id);

    return [$family, $users];
}

it('finds hits across modules, grouped by module', function () {
    [, $users] = searchFamily();
    [$alice] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Weekly groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($alice)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Bananas']);
    actingAs($alice)->post('/m/notes', ['title' => 'Banana bread recipe']);

    actingAs($alice)->get('/search?q=banana')
        ->assertInertia(function ($page) {
            $page->component('search')->where('q', 'banana')->has('results', 2);
            $modules = collect($page->toArray()['props']['results'])->pluck('module')->sort()->values()->all();
            expect($modules)->toBe(['notes', 'shopping']);
        });
});

it('requires at least 2 characters', function () {
    [, $users] = searchFamily();
    actingAs($users[0])->post('/m/notes', ['title' => 'Banana bread']);

    actingAs($users[0])->get('/search?q=b')
        ->assertInertia(fn ($page) => $page->has('results', 0));
});

it('does not return another member\'s private list', function () {
    [, $users] = searchFamily();
    [$alice, $bob] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Surprise party', 'visibility' => 'private']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($alice)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Balloons']);

    // The owner sees it...
    actingAs($alice)->get('/search?q=balloons')->assertInertia(fn ($page) => $page->has('results', 1));
    // ...the other member does not.
    actingAs($bob)->get('/search?q=balloons')->assertInertia(fn ($page) => $page->has('results', 0));
});

it('does not search a disabled module', function () {
    [$family, $users] = searchFamily();
    [$alice] = $users;

    actingAs($alice)->post('/m/notes', ['title' => 'Pineapple upside-down cake']);
    actingAs($alice)->get('/search?q=pineapple')->assertInertia(fn ($page) => $page->has('results', 1));

    app(ModuleRegistry::class)->disable('notes', $family->id);
    actingAs($alice)->get('/search?q=pineapple')->assertInertia(fn ($page) => $page->has('results', 0));
});

it('never returns another family\'s data', function () {
    [, $users] = searchFamily();
    [$alice] = $users;
    actingAs($alice)->post('/m/notes', ['title' => 'Mango smoothie recipe']);

    [, $otherUsers] = searchFamily();
    actingAs($otherUsers[0])->get('/search?q=mango')->assertInertia(fn ($page) => $page->has('results', 0));
});
