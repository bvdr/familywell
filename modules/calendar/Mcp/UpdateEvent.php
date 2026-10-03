<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Calendar\CalendarEvent;

class UpdateEvent extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_update_event';

    protected string $description = 'Change a calendar event. Send only the fields to change; people replaces the whole list. Changes apply to every occurrence of a repeating event.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required(), ...CalendarData::schema($schema)];
    }

    public function handle(Request $request): Response
    {
        $e = CalendarEvent::find((int) $request->get('id'));
        if (! $e || ! app(ShareAccess::class)->canEdit($this->user(), $e)) {
            return $this->notFound('Event');
        }
        $e->applyUpdate(collect($request->all())->except('id')->all());

        return Response::json(CalendarData::payload($e->fresh(), fn ($p) => $this->url($p)));
    }
}
