<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteItem extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_delete_item';

    protected string $description = 'Delete an inventory item with its history. A shopping-list row it added stays on the list. This is permanent and cannot be undone (these records have no trash): confirm with the person before calling it.';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $item = InventoryData::item($request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }
        $item->delete(); // same as the web delete (its log rows cascade)

        return Response::json(['deleted' => true, 'item_id' => $item->id, 'name' => $item->name]);
    }
}
