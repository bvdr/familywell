<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

use function Pest\Laravel\actingAs;

// A family with the shopping module installed, enabled, and active in session.
function shoppingFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/shopping')->assertNotFound();
});

it('renders the module settings page', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->get('/m/shopping/settings')->assertOk();
});

it('creates a list, adds an item, toggles it, and deletes it', function () {
    [, $users] = shoppingFamily();

    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekly Groceries', 'store' => 'Whole Foods'])->assertRedirect();
    $list = ShoppingList::withoutGlobalScopes()->first();
    expect($list->name)->toBe('Weekly Groceries')
        ->and($list->shares()->where('role', 'owner')->exists())->toBeTrue();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Bananas', 'department' => 'produce', 'quantity' => '2 bunches'])
        ->assertRedirect();
    $item = ShoppingItem::withoutGlobalScopes()->first();
    expect($item->name)->toBe('Bananas')->and($item->bought)->toBeFalse();

    // Empty body toggles bought.
    actingAs($users[0])->patch("/m/shopping/items/{$item->id}")->assertRedirect();
    expect($item->fresh()->bought)->toBeTrue();

    actingAs($users[0])->delete("/m/shopping/items/{$item->id}")->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(0);
});

it('hides a private list from a member who was not shared', function () {
    [, $users] = shoppingFamily();
    [$alice, $bob] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Surprise', 'visibility' => 'private']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $access = app(ShareAccess::class);
    expect($access->canView($alice, $list))->toBeTrue()   // owner sees it
        ->and($access->canView($bob, $list))->toBeFalse(); // other member does not
});

it('lets any member add to a family-visible list', function () {
    [, $users] = shoppingFamily();
    [$alice, $bob] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Shared list']); // defaults to family visibility
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($bob)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Eggs'])->assertRedirect();
    expect($list->items()->count())->toBe(1);
});

it('renames, changes visibility, and archives via updateList', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->patch("/m/shopping/lists/{$list->id}", ['name' => 'Renamed', 'visibility' => 'private', 'archived' => true])->assertRedirect();

    $list->refresh();
    expect($list->name)->toBe('Renamed')
        ->and($list->visibility())->toBe('private')
        ->and($list->archived_at)->not->toBeNull();
});

it('bulk-adds reviewed voice items to the list', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items/bulk", [
        'items' => [
            ['name' => 'Milk', 'quantity' => '1 gallon', 'department' => 'dairy'],
            ['name' => 'Bananas', 'department' => 'produce', 'note' => 'for the kids'],
        ],
    ])->assertRedirect();

    expect($list->items()->count())->toBe(2)
        ->and($list->items()->where('name', 'Milk')->value('department'))->toBe('dairy')
        ->and($list->items()->where('name', 'Bananas')->value('note'))->toBe('for the kids');
});

it('404s the voice parse endpoint when AI is not configured', function () {
    config()->set('services.gemini.key', null);
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/parse", ['transcript' => 'milk and eggs'])->assertNotFound();
});

it('duplicates a list with its items reset to unbought', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Original', 'store' => 'Target']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'department' => 'dairy']);
    ShoppingItem::withoutGlobalScopes()->first()->update(['bought' => true]);

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/duplicate")->assertRedirect();

    $copy = ShoppingList::withoutGlobalScopes()->where('name', 'Original (copy)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->store)->toBe('Target')
        ->and($copy->items()->count())->toBe(1)
        ->and($copy->items()->first()->bought)->toBeFalse()  // reset for the next trip
        ->and($copy->shares()->where('role', 'owner')->exists())->toBeTrue();
});
