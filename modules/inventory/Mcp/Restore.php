<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Inventory\InventoryTrash;

class Restore extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_restore';

    protected string $description = 'Use this when the user wants a deleted stock item back, with its quantity history. Takes an id from inventory_list_trash.';

    protected string $title = 'Restore stock item';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('Id from inventory_list_trash.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $request->validate(['id' => ['required', 'integer']])['id'];
        $path = InventoryTrash::restore($this->user(), 'inventory_item', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
