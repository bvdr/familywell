<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Support\Calendar\CalendarSources;
use App\Support\Mcp\McpTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Calendar\Occurrences;

#[IsReadOnly]
class ListCalendar extends Tool
{
    protected string $module = 'calendar';

    protected string $name = 'calendar_list';

    protected ?string $app = 'calendar';

    protected string $description = 'Everything on the family calendar between two dates: events (with repeats expanded), plus task due dates, birthdays, car due dates and project starts from modules this connection may read. Optional person filter.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->required()->description('Y-m-d'),
            'to' => $schema->string()->required()->description('Y-m-d, at most '.Occurrences::MAX_RANGE_DAYS.' days after from.'),
            'person' => $schema->string()->description('"user:<id>" or "person:<id>": only items linked to them.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.now()->parse((string) $request->get('from'))->addDays(Occurrences::MAX_RANGE_DAYS)->toDateString()],
            'person' => ['nullable', 'regex:/^(user|person):\d+$/'],
        ]);
        // Only modules the token can read (the calendar itself always, since this tool needs calendar:read).
        $readable = array_keys(McpTools::accessFromScopes($this->tokenScopes()));
        $items = collect(CalendarSources::collect($d['from'], $d['to'], $this->user()->id, [...$readable, 'calendar']))
            ->when($d['person'] ?? null, fn ($c, $key) => $c->filter(fn ($i) => collect($i['people'])->contains(fn ($p) => "{$p['type']}:{$p['id']}" === $key)))
            ->map(fn ($i) => ['url' => $this->url($i['href'])] + $i)->values();

        return Response::json(['from' => $d['from'], 'to' => $d['to'], 'items' => $items]);
    }
}
