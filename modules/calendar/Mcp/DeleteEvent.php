<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Calendar\CalendarEvent;

#[IsDestructive]
class DeleteEvent extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_event';

    protected string $description = 'Use this when the user wants to remove a calendar event. Removes every occurrence and the event\'s to-dos; they go to the trash and can be restored for 30 days with calendar_restore. Only the person who created the event can delete it.';

    protected string $title = 'Delete calendar event';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Calendar event id, from calendar_list_entries or calendar_get_event.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $e = CalendarEvent::find((int) $request->get('id'));
        if (! $e || $e->created_by !== $this->user()->id) { // creator only, like the web UI
            return $this->notFound('Event');
        }
        $e->delete();

        return Response::structured(['deleted' => true, 'id' => $e->id]);
    }
}
