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

    protected string $description = 'One calendar event with its people, to-dos, linked project and repeat rule.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $e = CalendarEvent::find((int) $request->get('id'));
        if (! $e || ! app(ShareAccess::class)->canView($this->user(), $e)) {
            return $this->notFound('Event');
        }

        return Response::structured(CalendarData::payload($e, fn ($p) => $this->url($p), withTodos: true));
    }
}
