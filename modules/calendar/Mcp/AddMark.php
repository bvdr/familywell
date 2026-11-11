<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Calendar\CalendarEventMark;

// "Mark days" on the event page (design C16): one short label on a set of the event's days. The tool takes dates
// (what a person says) and stores day indexes, like the app, so a repeating event's marks come back next time.
class AddMark extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_add_mark';

    protected string $description = 'Use this when the user wants to mark some days of an event that lasts more than one day with a short label, e.g. "Fish allowed" on the weekends of Lent or "Boat day" on a trip. The same label shows on each of those days, and on the same days of the next occurrence of a repeating event.';

    protected string $title = 'Mark event days';

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Calendar event id, from calendar_list_entries or calendar_get_event.')->required(),
            'name' => $schema->string()->description('The label shown on those days, e.g. "Fish allowed".')->required(),
            'icon' => $schema->string()->description('Lucide icon name for the label, e.g. fish, sailboat, star.'),
            'dates' => $schema->array()->items($schema->string())->description('The days to mark, each Y-m-d inside the event.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['dates' => ['required', 'array'], 'dates.*' => ['date_format:Y-m-d']]);
        $e = CalendarData::editableMultiDay($this->user(), $request->get('event_id'));
        if (! $e) {
            return Response::error('Event not found, you can\'t edit it, or it lasts only one day (only longer events have days to mark).');
        }
        $days = CalendarData::dayIndexes($e, $request->get('dates'));
        if ($days === null) {
            return Response::error('Every date must fall inside the event.');
        }
        if ($e->marks()->count() >= CalendarEventMark::MAX_PER_EVENT) {
            return Response::error('This event already has '.CalendarEventMark::MAX_PER_EVENT.' marks; change or delete one first.');
        }
        $mark = $e->marks()->create(CalendarEventMark::validated($e, ['days' => $days] + $request->all(), false));

        return Response::structured(CalendarData::markPayload($mark, $e->occurrenceAt($request->get('dates')[0] ?? null)[0]) + ['url' => $this->url($e->url())]);
    }
}
