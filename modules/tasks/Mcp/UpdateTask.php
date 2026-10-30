<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Notifications\Notify;

#[IsIdempotent]
#[IsDestructive]
class UpdateTask extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_update_task';

    protected string $description = 'Use this when the user wants to tick off a task or change it: title, note, due date or assignee. Only the fields you pass change; pass null to clear note, due_date or assigned_to.';

    protected string $title = 'Update to-do task';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Task id, from tasks_get_list or tasks_search.')->required(),
            'title' => $schema->string()->description('New title.'),
            'note' => $schema->string()->description('Short note (max 255 chars); null clears it.')->nullable(),
            'due_date' => $schema->string()->description('YYYY-MM-DD; null clears it.')->format('date')->nullable(),
            'assigned_to' => $schema->integer()->description('Member id from get_family; null unassigns.')->nullable(),
            'done' => $schema->boolean()->description('true marks it done, false marks it not done.'),
            ...TaskData::extensionSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(TaskData::memberIds())],
            'done' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user();
        $task = TaskData::task($user, $data['task_id']);
        if (! $task) {
            return $this->notFound('Task');
        }

        $before = $task->assigned_to;
        $wasDone = $task->done;
        $task->update(array_intersect_key($data, array_flip(['title', 'note', 'due_date', 'assigned_to', 'done'])));
        TaskData::fireSaved($task, $user, $request->all()); // extension fields + Vehicles' "maintenance done"

        // Same pings as the web UI: a genuine (re)assignment, and a task newly completed.
        $url = "/m/tasks/lists/{$task->task_list_id}";
        if (! empty($data['assigned_to']) && $data['assigned_to'] !== $before) {
            Notify::push(User::whereKey($data['assigned_to'])->get(), 'tasks', $user->id, 'Assigned to you', "{$user->name} assigned you: {$task->title}", $url);
        }
        if (($data['done'] ?? false) && ! $wasDone) {
            Notify::toAccessOf($task->list, 'tasks', $user->id, 'Task done', "{$user->name} completed: {$task->title}", $url);
        }
        TaskData::changed();

        return Response::structured(TaskData::taskPayload($task->fresh('assignee')));
    }
}
