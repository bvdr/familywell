<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteDayNote extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_day_note';

    protected string $description = 'Use this when the user wants the note removed from one day of an event. It goes to the trash and can be restored for 30 days with calendar_restore.';

    protected string $title = 'Delete event day note';

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Calendar event id, from calendar_list_entries or calendar_get_event.')->required(),
            'date' => $schema->string()->description('Y-m-d, the day whose note to remove.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $e = CalendarData::editableMultiDay($this->user(), $request->get('event_id'));
        $note = $e?->dayNotes()->where('date', $data['date'])->first();
        if (! $note) {
            return $this->notFound('Day note');
        }
        $note->delete();

        return Response::structured(['deleted' => true, 'type' => 'day_note', 'id' => $note->id, 'event_id' => $e->id, 'date' => $data['date']]);
    }
}
