<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\VehiclesTrash;

// Same list as Settings > Recently deleted (VehiclesTrash). Write access, like every module's trash tools:
// it only exists to pick something to restore.
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_list_trash';

    protected string $description = 'Use this when the user wants to find a car or car record they deleted (mileage reading, fuel fill, repair, document, maintenance schedule), to bring it back. Lists them newest first, with the date each is deleted for good; `type` + `id` go to vehicles_restore. Read-only.';

    protected string $title = 'List deleted car records';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(VehiclesTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
