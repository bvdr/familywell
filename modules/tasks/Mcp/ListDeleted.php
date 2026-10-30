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

    protected string $name = 'tasks_list_trash';

    protected string $description = 'Use this when the user wants to find a to-do list or task they deleted, to bring it back. Lists what this person can see in the Tasks trash, newest first, with the date each is deleted for good. Read-only.';

    protected string $title = 'List deleted to-dos';

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
