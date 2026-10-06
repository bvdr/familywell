<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Calendar\CalendarTrash;

// Same list as Settings > Recently deleted (CalendarTrash). Write access, like every module's trash tools:
// it only exists to pick something to restore.
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_list_deleted';

    protected string $description = 'Calendar events in the trash that this person can see, newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(CalendarTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
