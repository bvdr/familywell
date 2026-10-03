<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Calendar\CalendarEvent;

class DeleteEvent extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_event';

    protected string $description = 'Move a calendar event (all its occurrences and its to-dos) to Recently deleted for 30 days. Only the person who created it can delete it.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $e = CalendarEvent::find((int) $request->get('id'));
        if (! $e || $e->created_by !== $this->user()->id) { // creator only, like the web UI
            return $this->notFound('Event');
        }
        $e->delete();

        return Response::json(['deleted' => true, 'id' => $e->id]);
    }
}
