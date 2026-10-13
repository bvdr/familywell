<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteItem extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_delete_item';

    protected string $description = 'Move an inventory item (with its history) to the trash, restorable for 30 days with inventory_restore. A shopping-list row it added stays on the list.';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = InventoryData::item($request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }
        $item->delete(); // same as the web delete (30-day trash)

        return Response::structured(['deleted' => true, 'item_id' => $item->id, 'name' => $item->name]);
    }
}
