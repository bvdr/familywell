<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Tasks\TasksTrash;

// Same restore as Settings > Recently deleted (TasksTrash).
class Restore extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_restore';

    protected string $description = 'Use this when the user wants a deleted to-do list or task back. Restoring a list also restores the tasks deleted with it; restoring a task also restores its deleted list or parent task. Takes type and id from tasks_list_trash.';

    protected string $title = 'Restore to-do list or task';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('list or task, from tasks_list_trash.')->enum(['list', 'task'])->required(),
            'id' => $schema->integer()->description('The id from tasks_list_trash.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', ['list', 'task'])], 'id' => ['required', 'integer']]);
        $path = TasksTrash::restore($this->user(), $data['type'], (int) $data['id']);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        TaskData::changed();

        return Response::structured(['restored' => true, 'type' => $data['type'], 'id' => (int) $data['id'], 'url' => $this->url($path)]);
    }
}
