<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Tasks\TasksTrash;

// Same list as Settings > Recently deleted (TasksTrash).
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_list_deleted';

    protected string $description = 'What is in the Tasks trash (deleted lists and tasks this person can see), newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(TasksTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
