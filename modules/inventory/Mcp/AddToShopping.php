<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Inventory\InventoryReplenishment;

#[IsIdempotent]
class AddToShopping extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_add_to_shopping';

    protected string $description = 'Use this when the user wants to restock an item by putting it on the family\'s "Replenish" shopping list. Repeating it adds nothing while the item is still on the list unbought. When it is marked bought in Shopping, the stock level is topped back up automatically.';

    protected string $title = 'Add stock item to shopping';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->description('Stock item id, from inventory_list.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = InventoryData::item($request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }
        // The loop writes into Shopping's tables; with the module off the row would land on a list nobody
        // can open, and the assistant would report a success the family never sees. So refuse clearly.
        // (The web button does not check this today; the gap is only reachable by the stray click there.)
        if (! InventoryData::shoppingEnabled()) {
            return Response::error('Shopping is not turned on for this family, so the item cannot be added to a shopping list.');
        }

        $linkedBefore = $item->shopping_item_id;
        // Same idempotent path as the app's "Add to Shopping" button (InventoryReplenishment owns the rules).
        $row = app(InventoryReplenishment::class)->addToShopping($item, $this->user());

        return Response::structured([
            'already_on_list' => $linkedBefore === $row->id, // a stale link is replaced by a new row, so compare ids
            'shopping_item_id' => $row->id,
            'list_id' => $row->shopping_list_id,
            'quantity_hint' => $row->quantity,
            'url' => InventoryData::url("/m/shopping/lists/{$row->shopping_list_id}"),
        ]);
    }
}
