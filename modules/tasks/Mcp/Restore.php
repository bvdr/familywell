<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

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
        $data = $request->validate(['type' => ['required', 'in:list,task'], 'id' => ['required', 'integer']]);
        $user = $this->user();
        $access = app(ShareAccess::class);

        $model = $data['type'] === 'list'
            ? TaskList::onlyTrashed()->find($data['id'])
            : Task::onlyTrashed()->with(['list' => fn ($q) => $q->withTrashed()])->find($data['id']);
        $list = $model instanceof Task ? $model->list : $model;
        if (! $model || ! $list || $access->roleFor($user, $list) === null) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        $model->restore();
        TaskData::changed();

        return Response::json(['restored' => true, 'type' => $data['type'], 'id' => $model->id, 'url' => rtrim((string) config('app.url'), '/')."/m/tasks/lists/{$list->id}"]);
    }
}
