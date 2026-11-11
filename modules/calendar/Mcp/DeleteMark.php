<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteMark extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_delete_mark';

    protected string $description = 'Use this when the user wants a day mark taken off an event\'s days. It goes to the trash and can be restored for 30 days with calendar_restore.';

    protected string $title = 'Delete event day mark';

    public function schema(JsonSchema $schema): array
    {
        return ['mark_id' => $schema->integer()->description('The mark id, from calendar_get_event.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $found = CalendarData::mark($this->user(), $request->get('mark_id'));
        if (! $found) {
            return $this->notFound('Mark');
        }
        $found[0]->delete();

        return Response::structured(['deleted' => true, 'type' => 'mark', 'id' => $found[0]->id, 'event_id' => $found[1]->id]);
    }
}
