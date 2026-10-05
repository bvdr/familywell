<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

// Same path as the web UI's delete (CalendarEvent::deleteTask): soft-deleted with its sub-tasks, link removed.
// Like project tasks, a single listless to-do isn't listed in Recently deleted (only whole events are), so the
// description asks for confirmation instead of promising a restore.
#[IsDestructive]
class DeleteTodo extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_todo';

    protected string $description = 'Delete a to-do (and its sub-tasks) from a calendar event. It cannot be restored from Recently deleted on its own, so confirm with the person first.';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $found = CalendarData::todo($this->user(), $request->get('task_id'));
        if (! $found) {
            return $this->notFound('To-do');
        }
        [$task, $event] = $found;
        $event->deleteTask($task);

        return Response::structured(['deleted' => true, 'type' => 'todo', 'id' => $task->id, 'event_id' => $event->id]);
    }
}
