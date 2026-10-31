<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Calendar\CalendarEvent;

class CreateEvent extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_create_event';

    protected string $description = 'Use this when the user wants to put something on the family calendar: a trip, appointment, conference or holiday. All-day unless start_time and end_time are given. Can repeat, and can be linked to people and a project.';

    protected string $title = 'Create calendar event';

    public function schema(JsonSchema $schema): array
    {
        $s = CalendarData::schema($schema);
        $s['title'] = $s['title']->required();
        $s['start_date'] = $s['start_date']->required();
        $s['end_date'] = $s['end_date']->required();

        return $s;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $e = CalendarEvent::createFor($this->user(), CalendarEvent::validated($request->all()));

        return Response::structured(CalendarData::payload($e->fresh(), fn ($p) => $this->url($p)));
    }
}
