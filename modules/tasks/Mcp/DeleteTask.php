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

    protected string $description = 'Use this when the user wants a task (and its sub-tasks) deleted. It goes to the trash and can be restored for 30 days with tasks_restore. Do not use to tick a task off (that is tasks_update_task with done=true).';

    protected string $title = 'Delete to-do task';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->description('Task id, from tasks_get_list or tasks_search.')->required()];
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
