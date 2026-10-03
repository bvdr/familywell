<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Notifications\Notify;

class CreateTask extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_create_task';

    protected string $description = 'Add a task to a to-do list, optionally as a sub-task (one level deep), with a due date and an assignee.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'note' => $schema->string()->description('Short note (max 255 chars).'),
            'due_date' => $schema->string()->format('date')->description('YYYY-MM-DD.'),
            'assigned_to' => $schema->integer()->description('Member id from get_family.'),
            'parent_id' => $schema->integer()->description('Make it a sub-task of this top-level task in the same list.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::in(TaskData::memberIds())],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $user = $this->user();
        $list = TaskData::list($user, $data['list_id']);
        if (! $list) {
            return $this->notFound('List');
        }
        if (! empty($data['parent_id'])) {
            $parent = $list->tasks()->find($data['parent_id']);
            if (! $parent || $parent->parent_id !== null) {
                return Response::error('parent_id must be a top-level task in the same list (sub-tasks are one level deep).');
            }
        }

        $task = $list->addTask($user, $data);
        Notify::toAccessOf($list, 'tasks', $user->id, 'New task', "{$user->name} added: {$task->title}", "/m/tasks/lists/{$list->id}");
        TaskData::changed();

        return Response::json(TaskData::taskPayload($task->load('assignee')));
    }
}
