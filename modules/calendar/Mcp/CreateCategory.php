<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Calendar\CalendarCategory;
use Modules\Calendar\IconGuess;

// Any member may add one, like "New category" in the event sheet (CalendarCategoryController::store).
class CreateCategory extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_create_category';

    protected string $description = 'Use this when the user wants a new kind of event on the family calendar, e.g. "Sports" or "Doctor". Everyone in the family sees it as a filter. Then put events in it with calendar_create_event or calendar_update_event using the returned slug.';

    protected string $title = 'Create calendar category';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Up to 40 characters, e.g. "Sports".')->required(),
            'color' => $schema->string()->enum(CalendarCategory::COLORS)->description('The colour its events get.')->required(),
            'icon' => $schema->string()->description('Lucide icon name, e.g. volleyball. Leave it out and one is picked from the name.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $request->all();
        $input['icon'] ??= IconGuess::first((string) ($input['name'] ?? '')) ?: 'tag';
        $c = CalendarCategory::add(CalendarCategory::validated($input));

        return Response::structured(collect(CalendarCategory::forFamily())->firstWhere('id', $c->id));
    }
}
