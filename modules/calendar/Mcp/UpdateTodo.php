<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Tasks\Mcp\TaskData;

// Event to-dos are listless tasks, which tasks_update_task can't reach (it resolves through a list), so the
// calendar owns their edits, same as projects_update_task does for project tasks. Same path as the web UI:
// CalendarEvent::updateTask (which also notifies a new assignee).
#[IsIdempotent]
#[IsDestructive]
class UpdateTodo extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_update_todo';

    protected string $description = 'Use this when the user wants to tick off or change a to-do (or sub-task) on a calendar event: title, note, due date or assignee. Only the fields you pass change; pass null to clear note, due_date or assigned_to.';

    protected string $title = 'Update event to-do';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required()->description('The to-do id from calendar_get_event or calendar_add_todo.'),
            'title' => $schema->string()->description('New title.'),
            'note' => $schema->string()->description('Short note; null clears it.')->nullable(),
            'due_date' => $schema->string()->description('YYYY-MM-DD; null clears it.')->format('date')->nullable(),
            'assigned_to' => $schema->integer()->nullable()->description('Family member user id.'),
            'done' => $schema->boolean()->description('true marks it done, false marks it not done.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(TaskData::memberIds())],
            'done' => ['sometimes', 'boolean'],
        ]);
        $found = CalendarData::todo($this->user(), $data['task_id']);
        if (! $found) {
            return $this->notFound('To-do');
        }
        [$task, $event] = $found;
        $event->updateTask($this->user(), $task, array_intersect_key($data, array_flip(['title', 'note', 'due_date', 'assigned_to', 'done'])));

        return Response::structured(CalendarData::todoPayload($task->fresh(), $event, fn ($p) => $this->url($p)));
    }
}
