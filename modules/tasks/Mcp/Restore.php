<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Tasks\TasksTrash;

// Same restore as Settings > Recently deleted (TasksTrash).
class Restore extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_restore';

    protected string $description = 'Bring a list or task back from the trash. Restoring a list brings back the tasks deleted with it; restoring a task also restores its deleted list or parent task.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['list', 'task'])->required(),
            'id' => $schema->integer()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', ['list', 'task'])], 'id' => ['required', 'integer']]);
        $path = TasksTrash::restore($this->user(), $data['type'], (int) $data['id']);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        TaskData::changed();

        return Response::json(['restored' => true, 'type' => $data['type'], 'id' => (int) $data['id'], 'url' => $this->url($path)]);
    }
}
