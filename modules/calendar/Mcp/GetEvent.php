<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Calendar\CalendarEvent;

#[IsReadOnly]
class GetEvent extends Tool
{
    protected string $module = 'calendar';

    protected string $name = 'calendar_get_event';

    protected ?string $app = 'calendar';

    protected string $description = 'Use this when the user asks about one calendar event in detail. Returns its dates, people, to-dos, linked project and repeat rule and, for an event longer than one day, its days: the marks and the note on each day. Read-only.';

    protected string $title = 'Get calendar event';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Calendar event id, from calendar_list_entries.')->required(),
            'day' => $schema->string()->description('Y-m-d inside the occurrence whose days you want (a repeating event has one per year, say); omit for the first.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $e = CalendarEvent::find((int) $request->get('id'));
        if (! $e || ! app(ShareAccess::class)->canView($this->user(), $e)) {
            return $this->notFound('Event');
        }

        $day = $request->validate(['day' => ['nullable', 'date_format:Y-m-d']])['day'] ?? null;

        return Response::structured(CalendarData::payload($e, fn ($p) => $this->url($p), withTodos: true) + ['days' => CalendarData::days($e, $day)]);
    }
}
