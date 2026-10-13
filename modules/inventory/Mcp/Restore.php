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

    protected string $description = 'Bring a stock item back from the trash, with its quantity history.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('Id from inventory_list_deleted.')];
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
