<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

// One note per day of a multi-day event (design C17). Writing replaces that day's note, hence destructive.
#[IsIdempotent]
#[IsDestructive]
class SetDayNote extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_set_day_note';

    protected string $description = 'Use this when the user wants to write a note on one day of an event that lasts more than one day, e.g. "Ferry at 9, bring passports" on day 2 of a trip. Each day has one note; this replaces the note already on that day.';

    protected string $title = 'Write event day note';

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Calendar event id, from calendar_list_entries or calendar_get_event.')->required(),
            'date' => $schema->string()->description('Y-m-d, a day inside the event.')->required(),
            'body' => $schema->string()->description('The note, up to 500 characters.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'body' => ['required', 'string', 'max:500']]);
        $e = CalendarData::editableMultiDay($this->user(), $request->get('event_id'));
        if (! $e) {
            return Response::error('Event not found, you can\'t edit it, or it lasts only one day (only longer events have day notes).');
        }
        if (! $e->coversDate($data['date'])) {
            return Response::error('That date is not inside this event.');
        }
        $e->setDayNote($data['date'], $data['body']);

        return Response::structured(['event_id' => $e->id, 'date' => $data['date'], 'body' => trim($data['body']), 'url' => $this->url($e->url().'?day='.$data['date'])]);
    }
}
