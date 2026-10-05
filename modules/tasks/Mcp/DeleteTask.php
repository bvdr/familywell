<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Notifications\Notify;

#[IsDestructive]
class DeleteTask extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_delete_task';

    protected string $description = 'Move a task (and its sub-tasks) to the trash, restorable for 30 days with tasks_restore.';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $task = TaskData::task($user, $request->get('task_id'));
        if (! $task) {
            return $this->notFound('Task');
        }

        $task->delete();
        Notify::toAccessOf($task->list, 'tasks', $user->id, $task->list->name, "{$user->name} deleted: {$task->title}", "/m/tasks/lists/{$task->task_list_id}");
        TaskData::changed();

        return Response::structured(['deleted' => true, 'type' => 'task', 'id' => $task->id] + TaskData::trashInfo($task));
    }
}
