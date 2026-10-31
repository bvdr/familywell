<?php

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryLog;
use Modules\Shopping\ShoppingItem;

// Household stock over MCP: list / inspect / triage, then add, edit, adjust and push to Shopping through the
// same model paths the web controller uses. Stock is family-wide, so the family scope is the only access rule.

function stockToken(array $access = ['inventory' => 'write'], array $modules = ['inventory', 'shopping']): array
{
    [$family, $users] = mcpFamily($modules);
    CurrentFamily::set($family->id);
    $milk = InventoryItem::create(['name' => 'Milk', 'quantity' => 1, 'unit' => 'L', 'min_quantity' => 2, 'target_quantity' => 4, 'category' => 'food', 'location' => 'fridge']);
    $soap = InventoryItem::create(['name' => 'Dish soap', 'quantity' => 3, 'unit' => 'bottles', 'min_quantity' => 1, 'category' => 'cleaning', 'location' => 'kitchen']);
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    return [$family, $users, $token, $milk, $soap];
}

it('lists, filters, shows and triages stock', function () {
    [, , $token, $milk, $soap] = stockToken(['inventory' => 'read']);

    [, $all] = mcpTool($token, 'inventory_list');
    expect(collect($all['items'])->pluck('name')->all())->toBe(['Dish soap', 'Milk'])
        ->and($all['categories'])->toHaveKey('food');

    [, $low] = mcpTool($token, 'inventory_list', ['state' => 'needs_replenishing']);
    expect($low['items'])->toHaveCount(1)
        ->and($low['items'][0])->toMatchArray(['id' => $milk->id, 'state' => 'low', 'quantity' => 1, 'unit' => 'L'])
        ->and($low['items'][0]['url'])->toEndWith("/m/inventory/items/{$milk->id}");

    [, $found] = mcpTool($token, 'inventory_list', ['search' => 'soap', 'location' => 'kitchen']);
    expect(collect($found['items'])->pluck('id')->all())->toBe([$soap->id]);

    [, $got] = mcpTool($token, 'inventory_get', ['item_id' => $milk->id]);
    expect($got['replenish_to'])->toEqual(4);

    [, $att] = mcpTool($token, 'inventory_list_needs_attention');
    expect($att['total'])->toBe(1)->and($att['low'][0]['id'])->toBe($milk->id);

    // Read-only token: no write tools.
    expect(mcpToolNames($token))->toContain('inventory_get')->not->toContain('inventory_adjust_quantity');
});

it('adds an item with its opening log and edits only the fields sent', function () {
    [, , $token] = stockToken();

    [$err, $added] = mcpTool($token, 'inventory_add_item', ['name' => 'Rice', 'unit' => 'kg', 'quantity' => 2, 'min_quantity' => 1, 'category' => 'Grains', 'expires_on' => '2030-01-01']);
    expect($err)->toBeFalse()->and($added)->toMatchArray(['name' => 'Rice', 'category' => 'grains', 'expires_on' => '2030-01-01', 'state' => 'good'])
        ->and(InventoryLog::where('inventory_item_id', $added['id'])->value('type'))->toBe('added');

    [, $edited] = mcpTool($token, 'inventory_update_item', ['item_id' => $added['id'], 'min_quantity' => 3, 'notes' => 'Basmati']);
    expect($edited)->toMatchArray(['name' => 'Rice', 'min_quantity' => 3, 'quantity' => 2, 'expires_on' => '2030-01-01', 'state' => 'low']);

    // Same rules as the form: an unknown unit is rejected.
    expect(mcpTool($token, 'inventory_update_item', ['item_id' => $added['id'], 'unit' => 'tons'])[0])->toBeTrue();
});

it('adjusts quantity by a change or to a total, logging like the app', function () {
    [, , $token, $milk] = stockToken();

    [, $used] = mcpTool($token, 'inventory_adjust_quantity', ['item_id' => $milk->id, 'change' => -1]);
    expect($used)->toMatchArray(['quantity' => 0, 'state' => 'empty']);

    [, $counted] = mcpTool($token, 'inventory_adjust_quantity', ['item_id' => $milk->id, 'quantity' => 4]);
    expect($counted['quantity'])->toEqual(4)
        ->and(InventoryLog::where('inventory_item_id', $milk->id)->pluck('type')->sort()->values()->all())->toBe(['added', 'consumed']);

    [$err, $msg] = mcpTool($token, 'inventory_adjust_quantity', ['item_id' => $milk->id, 'change' => -10]);
    expect($err)->toBeTrue()->and($msg)->toContain('cannot go down');
});

it('adds to Shopping once, and refuses when Shopping is off', function () {
    [$family, , $token, $milk] = stockToken();

    [, $first] = mcpTool($token, 'inventory_add_to_shopping', ['item_id' => $milk->id]);
    [, $again] = mcpTool($token, 'inventory_add_to_shopping', ['item_id' => $milk->id]);
    expect($first['already_on_list'])->toBeFalse()->and($first['quantity_hint'])->toBe('to 4 L')
        ->and($again['already_on_list'])->toBeTrue()->and($again['shopping_item_id'])->toBe($first['shopping_item_id'])
        ->and(ShoppingItem::count())->toBe(1);

    app(ModuleRegistry::class)->disable('shopping', $family->id);
    [$err, $msg] = mcpTool($token, 'inventory_add_to_shopping', ['item_id' => $milk->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Shopping');
});

it('never reaches another family\'s stock', function () {
    [, , $token] = stockToken();

    [$other] = mcpFamily(['inventory']);
    CurrentFamily::set($other->id);
    $theirs = InventoryItem::create(['name' => 'Coffee', 'quantity' => 5, 'unit' => 'packs']);

    expect(mcpTool($token, 'inventory_get', ['item_id' => $theirs->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'inventory_adjust_quantity', ['item_id' => $theirs->id, 'quantity' => 0])[0])->toBeTrue()
        ->and(mcpTool($token, 'inventory_update_item', ['item_id' => $theirs->id, 'name' => 'Mine'])[0])->toBeTrue();
    [, $list] = mcpTool($token, 'inventory_list');
    expect(collect($list['items'])->pluck('name'))->not->toContain('Coffee');

    expect($theirs->fresh())->quantity->toEqual(5)->name->toBe('Coffee');
});

it('trashes an item with its history, restores it, and clears a category deleted meanwhile', function () {
    [, , $token, $milk] = stockToken();
    mcpTool($token, 'inventory_adjust_quantity', ['item_id' => $milk->id, 'change' => -1]);
    $milk->update(['category' => 'snacks']); // a category that will be gone by the restore

    [$err] = mcpTool($token, 'inventory_delete_item', ['item_id' => $milk->id]);
    expect($err)->toBeFalse()->and(collect(mcpTool($token, 'inventory_list')[1]['items'])->pluck('name')->all())->toBe(['Dish soap']);
    [, $trash] = mcpTool($token, 'inventory_list_trash');
    expect($trash['deleted'][0])->toMatchArray(['type' => 'inventory_item', 'id' => $milk->id, 'title' => 'Milk', 'where' => 'Inventory']);

    [$err, $r] = mcpTool($token, 'inventory_restore', ['id' => $milk->id]);
    expect($err)->toBeFalse()->and($r['url'])->toEndWith("/m/inventory/items/{$milk->id}");
    $back = InventoryItem::find($milk->id);
    expect($back->category)->toBeNull()->and($back->location)->toBe('fridge')
        ->and(InventoryLog::where('inventory_item_id', $milk->id)->count())->toBeGreaterThan(0);
});

it('does not top up a trashed item when its shopping row is bought, and purges it with its history', function () {
    [, $users, $token, $milk] = stockToken();
    [, $on] = mcpTool($token, 'inventory_add_to_shopping', ['item_id' => $milk->id]);
    mcpTool($token, 'inventory_delete_item', ['item_id' => $milk->id]);

    $row = ShoppingItem::find($on['shopping_item_id']);
    $row->update(['bought' => true]);
    $row->settleBought(false, $users[0]);
    expect(InventoryItem::onlyTrashed()->find($milk->id)->quantity)->toBe(1.0);

    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertExitCode(0);
    $this->assertDatabaseMissing('inventory_items', ['id' => $milk->id]);
    $this->assertDatabaseMissing('inventory_log', ['inventory_item_id' => $milk->id]);
});
