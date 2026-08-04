<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use Modules\Shopping\ShoppingAisle;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;

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

it('materializes default aisles, then adds and deletes a custom one', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure")->assertOk();
    expect(collect($res->json('aisles'))->pluck('slug'))->toContain('produce', 'other')
        ->and($res->json('templates'))->not->toBeEmpty();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles", ['name' => 'Deli', 'icon' => 'ham'])->assertOk();
    $deli = collect($res->json('aisles'))->firstWhere('name', 'Deli');
    expect($deli['slug'])->toBe('deli')->and($deli['icon'])->toBe('ham');

    actingAs($users[0])->deleteJson("/m/shopping/aisles/{$deli['id']}")->assertOk();
    expect(ShoppingAisle::withoutGlobalScopes()->where('slug', 'deli')->exists())->toBeFalse();
});

it('stores an item with a primary store and alternatives', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", [
        'name' => 'Milk', 'store' => 'Metro', 'alt_stores' => ['Dedeman', 'Kaufland'],
    ])->assertRedirect();

    $item = ShoppingItem::withoutGlobalScopes()->where('name', 'Milk')->first();
    expect($item->store)->toBe('Metro')->and($item->alt_stores)->toBe(['Dedeman', 'Kaufland']);
});

it('edits aisles for a chosen store, not just the list default', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure", ['store' => 'Dedeman'])->assertOk();
    expect($res->json('store'))->toBe('Dedeman')
        ->and(ShoppingAisle::withoutGlobalScopes()->where('store', 'Dedeman')->exists())->toBeTrue()
        ->and(ShoppingAisle::withoutGlobalScopes()->where('store', 'Metro')->exists())->toBeFalse();
});

it('applies a shop template, replacing the aisles', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'DIY', 'store' => 'Dedeman']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/template", ['template' => 'bricolage'])->assertOk();
    $slugs = collect($res->json('aisles'))->pluck('slug');
    expect($slugs)->toContain('hand-tools', 'plumbing')->and($slugs)->not->toContain('dairy');
});

it('accepts a custom aisle slug on an item and groups under it', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'DIY', 'store' => 'Dedeman']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/template", ['template' => 'bricolage']);

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Hammer', 'department' => 'hand-tools'])->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->where('name', 'Hammer')->value('department'))->toBe('hand-tools');
});

it('scopes aisles to the family (another family cannot touch them)', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'G', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure")->assertOk();
    $aisleId = $res->json('aisles.0.id');

    // A user from a different family gets a 404 on the family-scoped aisle model binding.
    [, $others] = shoppingFamily();
    actingAs($others[0])->deleteJson("/m/shopping/aisles/{$aisleId}")->assertNotFound();
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

it('only offers members with access as assignees on a private list', function () {
    [, $users] = shoppingFamily(); // owner + one other member
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Secret']);
    $list = ShoppingList::withoutGlobalScopes()->latest('id')->first();
    $list->setVisibility('private');

    actingAs($users[0])->get("/m/shopping/lists/{$list->id}")
        ->assertInertia(fn ($p) => $p->has('members', 1)->where('members.0.id', $users[0]->id));
});

it('aggregates a shop\'s items across accessible lists, including "also available at"', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Casa']);
    $lists = ShoppingList::withoutGlobalScopes()->orderBy('id')->get();

    actingAs($users[0])->post("/m/shopping/lists/{$lists[0]->id}/items", ['name' => 'Bananas', 'store' => 'Metro']);
    actingAs($users[0])->post("/m/shopping/lists/{$lists[1]->id}/items", ['name' => 'Apples', 'store' => 'Metro']);
    // Primary Kaufland but also available at Metro -> shows under both.
    actingAs($users[0])->post("/m/shopping/lists/{$lists[0]->id}/items", ['name' => 'Milk', 'store' => 'Kaufland', 'alt_stores' => ['Metro']]);

    $metro = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();
    actingAs($users[0])->get("/m/shopping/shops/{$metro->id}")
        ->assertInertia(fn ($p) => $p->component('shopping/shop')->has('items', 3));

    $kaufland = ShoppingShop::withoutGlobalScopes()->where('name', 'Kaufland')->first();
    actingAs($users[0])->get("/m/shopping/shops/{$kaufland->id}")
        ->assertInertia(fn ($p) => $p->has('items', 1)); // only Milk
});

it('renames a shop and cascades the name across items', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Bananas', 'store' => 'Metro']);
    $shop = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();

    actingAs($users[0])->patch("/m/shopping/shops/{$shop->id}", ['name' => 'Metro Cash & Carry'])->assertRedirect();

    expect(ShoppingShop::withoutGlobalScopes()->find($shop->id)->name)->toBe('Metro Cash & Carry')
        ->and(ShoppingItem::withoutGlobalScopes()->where('name', 'Bananas')->first()->store)->toBe('Metro Cash & Carry');
});

it('adds an item from a shop page into the default list with the shop preset', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'store' => 'Metro']);
    $shop = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();

    // No list_id -> lands in the user's default (most recent) list, with Metro preset as the store.
    actingAs($users[0])->post("/m/shopping/shops/{$shop->id}/items", ['name' => 'Cheese'])->assertRedirect();

    $item = ShoppingItem::withoutGlobalScopes()->where('name', 'Cheese')->first();
    expect($item->store)->toBe('Metro')->and($item->shopping_list_id)->toBe($list->id);
});

it('creates a shop from the Shops tab', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/shops', ['name' => 'Lidl'])->assertRedirect();
    expect(ShoppingShop::withoutGlobalScopes()->where('name', 'Lidl')->exists())->toBeTrue();
});
