<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Calendar\CalendarTrash;

// Same restore as Settings > Recently deleted (CalendarTrash); the event's to-dos come back with it
// (CalendarEvent::booted). `id` like calendar_delete_event, so the pair reads the same.
class Restore extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_restore';

    protected string $description = 'Use this when the user wants a deleted calendar event (with the to-dos deleted with it) or a single event to-do back. Takes the id and type from calendar_list_trash.';

    protected string $title = 'Restore calendar item';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id from calendar_list_trash.'),
            'type' => $schema->string()->enum(['calendar_event', 'todo'])->description('The entry\'s type from calendar_list_trash (default calendar_event).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['id' => ['required', 'integer'], 'type' => ['nullable', 'in:calendar_event,todo']]);
        $id = (int) $data['id'];
        $path = CalendarTrash::restore($this->user(), $data['type'] ?? 'calendar_event', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
