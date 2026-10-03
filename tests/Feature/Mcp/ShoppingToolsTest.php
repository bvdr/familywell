<?php

use App\Support\Modules\Hooks;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

function shoppingToken(int $members = 2): array
{
    [$family, $users] = mcpFamily(['shopping'], $members);
    $token = mcpConnect($users[0], $family->id, ['shopping' => 'write'])['access_token'];

    return [$family, $users, $token];
}

it('creates a list, adds items, warns on a duplicate, and reads them back', function () {
    [, , $token] = shoppingToken();

    [$err, $list] = mcpTool($token, 'shopping_create_list', ['name' => 'Groceries', 'store' => 'Lidl']);
    expect($err)->toBeFalse()->and($list['store'])->toBe('Lidl');

    [, $milk] = mcpTool($token, 'shopping_add_item', ['list_id' => $list['id'], 'name' => 'Milk', 'quantity' => '2']);
    expect($milk['name'])->toBe('Milk')->and($milk)->not->toHaveKey('warning');

    // Soft duplicate check: still added, but the assistant is told.
    [$err, $again] = mcpTool($token, 'shopping_add_item', ['list_id' => $list['id'], 'name' => ' milk ']);
    expect($err)->toBeFalse()->and($again['warning'])->toContain('Milk was already on this list');

    [, $got] = mcpTool($token, 'shopping_get_list', ['list_id' => $list['id']]);
    expect($got['items'])->toHaveCount(2);

    [, $lists] = mcpTool($token, 'shopping_list_lists');
    expect(collect($lists['lists'])->firstWhere('id', $list['id'])['to_buy_count'])->toBe(2);

    [, $found] = mcpTool($token, 'shopping_search', ['query' => 'MILK']);
    expect($found['items'])->toHaveCount(2);
});

it('ticks an item off as bought, firing the same hook as the app (Inventory restock)', function () {
    [, , $token] = shoppingToken();
    $fired = 0;
    app(Hooks::class)->listen('shopping.item.bought', function () use (&$fired) {
        $fired++;
    });
    [, $list] = mcpTool($token, 'shopping_create_list', ['name' => 'L']);
    [, $item] = mcpTool($token, 'shopping_add_item', ['list_id' => $list['id'], 'name' => 'Eggs']);

    [, $upd] = mcpTool($token, 'shopping_update_item', ['item_id' => $item['id'], 'bought' => true, 'quantity' => '12']);
    mcpTool($token, 'shopping_update_item', ['item_id' => $item['id'], 'bought' => true]); // already bought: no second fire
    expect($upd['bought'])->toBeTrue()->and($upd['quantity'])->toBe('12')->and($fired)->toBe(1);

    [, $l] = mcpTool($token, 'shopping_update_list', ['list_id' => $list['id'], 'name' => 'Renamed', 'archived' => true]);
    expect($l['name'])->toBe('Renamed')->and($l['archived'])->toBeTrue();
});

it('deletes to the trash, lists the trash, and restores', function () {
    [, , $token] = shoppingToken();
    [, $list] = mcpTool($token, 'shopping_create_list', ['name' => 'Party']);
    [, $item] = mcpTool($token, 'shopping_add_item', ['list_id' => $list['id'], 'name' => 'Balloons']);

    [, $d] = mcpTool($token, 'shopping_delete_item', ['item_id' => $item['id']]);
    expect($d['type'])->toBe('item');
    $this->travel(1)->minutes(); // the list is deleted later than the item (trash timestamps are per second)
    [, $dl] = mcpTool($token, 'shopping_delete_list', ['list_id' => $list['id']]);
    expect($dl['deleted'])->toBeTrue();

    [, $trash] = mcpTool($token, 'shopping_list_deleted');
    expect(collect($trash['deleted'])->pluck('type')->sort()->values()->all())->toBe(['item', 'list']);

    // The item was trashed on its own before the list, so restoring the list leaves it in the trash...
    mcpTool($token, 'shopping_restore', ['type' => 'list', 'id' => $list['id']]);
    expect(ShoppingItem::withoutGlobalScope('family')->find($item['id']))->toBeNull();
    // ...until it is restored itself.
    [, $r] = mcpTool($token, 'shopping_restore', ['type' => 'item', 'id' => $item['id']]);
    expect($r['restored'])->toBeTrue()->and(ShoppingItem::withoutGlobalScope('family')->find($item['id']))->not->toBeNull();
});

it('cannot see or touch another member\'s private list, nor delete a list it did not create', function () {
    [$family, $users, $token] = shoppingToken();
    CurrentFamily::set($family->id);
    $private = ShoppingList::createFor($users[1], ['name' => 'Gifts'], 'private');
    $hidden = $private->items()->create(['name' => 'Surprise', 'created_by' => $users[1]->id]);
    $shared = ShoppingList::createFor($users[1], ['name' => 'Shared']);

    [, $lists] = mcpTool($token, 'shopping_list_lists');
    expect(collect($lists['lists'])->pluck('name')->all())->toBe(['Shared']);
    [, $found] = mcpTool($token, 'shopping_search', ['query' => 'Surprise']);
    expect($found['items'])->toBe([]);

    expect(mcpTool($token, 'shopping_get_list', ['list_id' => $private->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'shopping_add_item', ['list_id' => $private->id, 'name' => 'x'])[0])->toBeTrue()
        ->and(mcpTool($token, 'shopping_update_item', ['item_id' => $hidden->id, 'bought' => true])[0])->toBeTrue()
        ->and(mcpTool($token, 'shopping_delete_list', ['list_id' => $shared->id])[1])->toContain('created this list');
    expect($hidden->fresh()->bought)->toBeFalse();
});
