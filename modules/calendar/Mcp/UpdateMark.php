<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Calendar\CalendarEventMark;

#[IsIdempotent]
#[IsDestructive]
class UpdateMark extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_update_mark';

    protected string $description = 'Use this when the user wants to rename a day mark on an event, change its icon, or change which days carry it. Only the fields you pass change; `dates` replaces the whole set of days.';

    protected string $title = 'Update event day mark';

    public function schema(JsonSchema $schema): array
    {
        return [
            'mark_id' => $schema->integer()->description('The mark id, from calendar_get_event.')->required(),
            'name' => $schema->string()->description('New label.'),
            'icon' => $schema->string()->nullable()->description('Lucide icon name; null removes it.'),
            'dates' => $schema->array()->items($schema->string())->description('Every day that should carry the mark, each Y-m-d inside the event.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['dates' => ['sometimes', 'array'], 'dates.*' => ['date_format:Y-m-d']]);
        $found = CalendarData::mark($this->user(), $request->get('mark_id'));
        if (! $found) {
            return $this->notFound('Mark');
        }
        [$mark, $e] = $found;
        $input = collect($request->all())->only(['name', 'icon'])->all();
        if ($request->has('dates')) {
            $input['days'] = CalendarData::dayIndexes($e, $request->get('dates'));
            if ($input['days'] === null) {
                return Response::error('Every date must fall inside the event.');
            }
        }
        $mark->update(CalendarEventMark::validated($e, $input, true));

        return Response::structured(CalendarData::markPayload($mark->fresh(), $e->occurrenceAt($request->get('dates')[0] ?? null)[0]) + ['url' => $this->url($e->url())]);
    }
}
