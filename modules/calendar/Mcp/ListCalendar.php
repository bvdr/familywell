<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Calendar\CalendarSources;
use App\Support\Mcp\McpTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Calendar\Occurrences;

#[IsReadOnly]
class ListCalendar extends Tool
{
    protected string $module = 'calendar';

    protected string $name = 'calendar_list_entries';

    protected ?string $app = 'calendar';

    protected string $description = 'Use this when the user asks what is happening between two dates. Returns calendar events (repeats expanded) plus, from modules this connection may read, task due dates, birthdays, car due dates and project starts. Optionally only items linked to one person, or only some sources. Read-only.';

    protected string $title = 'List calendar entries';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->required()->description('Y-m-d'),
            'to' => $schema->string()->required()->description('Y-m-d, at most '.Occurrences::MAX_RANGE_DAYS.' days after from.'),
            'person' => $schema->string()->description('"user:<id>" or "person:<id>": only items linked to them.'),
            'sources' => $schema->array()->items($schema->string())
                ->description('Module ids to include: calendar, tasks, birthdays, vehicles, projects. Omit for all this connection may read.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Two steps: the 400-day cap needs a parsed `from`, and parsing an unvalidated one ("abc") would throw.
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'person' => ['nullable', 'regex:/^(user|person):\d+$/'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string'],
        ]);
        $request->validate(['to' => ['before_or_equal:'.now()->parse($d['from'])->addDays(Occurrences::MAX_RANGE_DAYS)->toDateString()]]);
        // Only modules the token can read (the calendar itself always, since this tool needs calendar:read).
        $only = [...array_keys(McpTools::accessFromScopes($this->tokenScopes())), 'calendar'];
        // `sources` can only narrow what the token may read; unknown ids simply match nothing. An empty list
        // means "no filter" (assistants send [] for that), never "nothing" — and it still can't widen access.
        if (! empty($d['sources'])) {
            $only = array_values(array_intersect($only, $d['sources']));
        }
        $items = collect(CalendarSources::collect($d['from'], $d['to'], $this->user()->id, $only))
            ->when($d['person'] ?? null, fn ($c, $key) => $c->filter(fn ($i) => collect($i['people'])->contains(fn ($p) => "{$p['type']}:{$p['id']}" === $key)))
            ->map(fn ($i) => ['url' => $this->url($i['href'])] + $i)->values();

        return Response::structured(['from' => $d['from'], 'to' => $d['to'], 'items' => $items]);
    }
}
