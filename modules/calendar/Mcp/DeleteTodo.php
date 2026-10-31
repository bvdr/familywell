<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

// Same path as the web UI's delete (CalendarEvent::deleteTask): trashed with its sub-tasks, link kept for calendar_restore.
// Like project tasks, a single listless to-do isn't listed in Recently deleted (only whole events are), so the
// description asks for confirmation instead of promising a restore.
#[IsDestructive]
class DeleteTodo extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_todo';

    protected string $description = 'Use this when the user wants to remove a to-do (and its sub-tasks) from a calendar event. It goes to the trash and can be restored for 30 days with calendar_restore.';

    protected string $title = 'Delete event to-do';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->description('The to-do id, from calendar_get_event.')->required()];
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
