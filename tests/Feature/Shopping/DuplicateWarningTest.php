<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

use function Pest\Laravel\actingAs;

// Soft duplicate warning on add: an active item with the same normalized name already on the list
// answers with a validation message on `add_anyway`; re-posting with add_anyway=1 always goes through.

function dupShoppingList(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    actingAs($user)->post('/m/shopping/lists', ['name' => 'Groceries']);

    return [$user, ShoppingList::withoutGlobalScopes()->first()];
}

it('warns when the same item is already on the list, but still adds with add_anyway', function () {
    [$user, $list] = dupShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk'])->assertSessionDoesntHaveErrors();

    // Case, surrounding whitespace and punctuation don't make it a different item.
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => '  milk. '])
        ->assertSessionHasErrors(['add_anyway' => 'Milk is already on this list.']);
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);

    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'milk', 'add_anyway' => 1])->assertSessionDoesntHaveErrors();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2);
});

it('does not warn for a different name or for an item already bought', function () {
    [$user, $list] = dupShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk']);
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Eggs'])->assertSessionDoesntHaveErrors();

    $milk = ShoppingItem::withoutGlobalScopes()->where('name', 'Milk')->first();
    actingAs($user)->patch("/m/shopping/items/{$milk->id}", ['bought' => true]);
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'milk'])->assertSessionDoesntHaveErrors();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(3);
});

it('only looks at the same list', function () {
    [$user, $list] = dupShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk']);
    actingAs($user)->post('/m/shopping/lists', ['name' => 'Hardware']);
    $other = ShoppingList::withoutGlobalScopes()->where('name', 'Hardware')->first();

    actingAs($user)->post("/m/shopping/lists/{$other->id}/items", ['name' => 'Milk'])->assertSessionDoesntHaveErrors();
});

it('de-dupes repeated names within one pasted batch', function () {
    [$user, $list] = dupShoppingList();
    $res = actingAs($user)->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => "milk\neggs\nMilk\nbread"])->assertOk();
    expect(collect($res->json('items'))->pluck('name')->map(fn ($n) => strtolower($n))->all())->toBe(['milk', 'eggs', 'bread']);
});

it('tags pasted rows that are already on the list, without dropping them', function () {
    [$user, $list] = dupShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk']);
    $res = actingAs($user)->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => "milk\neggs"])->assertOk();
    expect(collect($res->json('items'))->map(fn ($i) => [$i['name'], $i['on_list'] ?? false])->all())->toBe([['milk', true], ['eggs', false]]);
});
