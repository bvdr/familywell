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

    protected string $description = 'Bring a calendar event back from the trash, with its to-dos.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('Event id from calendar_list_deleted.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $request->validate(['id' => ['required', 'integer']])['id'];
        $path = CalendarTrash::restore($this->user(), 'calendar_event', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
