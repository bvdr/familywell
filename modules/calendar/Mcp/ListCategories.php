<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Calendar\CalendarCategory;

#[IsReadOnly]
class ListCategories extends Tool
{
    protected string $module = 'calendar';

    protected string $name = 'calendar_list_categories';

    protected string $description = 'Use this when the user asks which kinds of events the calendar has (trips, school, work…) or before putting an event in a category the family added. Returns every category with its slug, name, icon and colour. Read-only.';

    protected string $title = 'List calendar categories';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // `id` is null for the five built-ins: they live in code and can't be renamed.
        return Response::structured(['categories' => CalendarCategory::forFamily()]);
    }
}
