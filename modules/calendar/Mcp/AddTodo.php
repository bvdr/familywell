<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Calendar\CalendarEvent;

// The tasks tools can't attach a task to an event, so this creates it; ticking/editing it afterwards goes
// through the existing tasks_update_task.
class AddTodo extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_add_todo';

    protected string $description = 'Add a to-do inside a calendar event (e.g. "book hotel" on a trip). Use tasks_update_task to tick or edit it later.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'due_date' => $schema->string()->description('Y-m-d'),
            'assigned_to' => $schema->integer()->description('Family member user id.'),
            'parent_id' => $schema->integer()->description('Make it a sub-task of one of the event\'s to-dos.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $e = CalendarEvent::find($data['event_id']);
        if (! $e || ! app(ShareAccess::class)->canEdit($this->user(), $e)) {
            return $this->notFound('Event');
        }
        $t = $e->addTask($this->user(), $data);

        return Response::json(['id' => $t->id, 'title' => $t->title, 'done' => false, 'event_id' => $e->id]);
    }
}
