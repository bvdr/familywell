<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Inventory\InventoryTrash;

// Same list as Settings > Recently deleted (InventoryTrash). Write access, like every module's trash tools:
// it only exists to pick something to restore.
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_list_deleted';

    protected string $description = 'Stock items in the trash, newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(InventoryTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
