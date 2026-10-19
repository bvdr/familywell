<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;

use function Pest\Laravel\actingAs;

// The PWA queues shopping adds/ticks made with no connection and replays them (resources/js/lib/shopping-outbox.tsx).
// A replay can repeat a request the server already handled, so both must be safe to run twice.

function replayShoppingList(): array
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

it('adds an item once when the same client_key arrives twice', function () {
    [$user, $list] = replayShoppingList();

    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'client_key' => 'k1'])->assertRedirect();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'client_key' => 'k1', 'add_anyway' => 1])->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);

    // A different key is a different add.
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'client_key' => 'k2', 'add_anyway' => 1]);
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2);
});

it('does not use up the key on a duplicate warning', function () {
    [$user, $list] = replayShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk']);

    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'client_key' => 'k1'])->assertSessionHasErrors('add_anyway');
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'client_key' => 'k1', 'add_anyway' => 1]);
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2);
});

it('adds from a shop page once per client_key', function () {
    [$user, $list] = replayShoppingList();
    actingAs($user)->post('/m/shopping/shops', ['name' => 'Lidl']);
    $shop = ShoppingShop::withoutGlobalScopes()->firstOrFail();

    foreach ([1, 2] as $_) {
        actingAs($user)->post("/m/shopping/shops/{$shop->id}/items", ['name' => 'Bread', 'list_id' => $list->id, 'client_key' => 'k1', 'add_anyway' => 1]);
    }
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);
});

it('sets bought on a replayed tick instead of flipping it back', function () {
    [$user, $list] = replayShoppingList();
    actingAs($user)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Eggs']);
    $item = ShoppingItem::withoutGlobalScopes()->firstOrFail();

    actingAs($user)->patch("/m/shopping/items/{$item->id}", ['bought' => true]);
    actingAs($user)->patch("/m/shopping/items/{$item->id}", ['bought' => true]);
    expect($item->fresh()->bought)->toBeTrue();
});
