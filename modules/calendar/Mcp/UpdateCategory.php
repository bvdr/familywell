<?php

namespace Modules\Calendar\Mcp;

use App\Mcp\Tool;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Calendar\CalendarCategory;

// Renaming changes everyone's filter chips, so it's family-admin only, like CalendarCategoryController::update.
// No delete tool: deleting one takes it off its events for good, which the trash can't undo, so it stays in the app.
#[IsIdempotent]
#[IsDestructive]
class UpdateCategory extends Tool
{
    protected string $module = 'calendar';

    protected string $access = 'write';

    protected string $name = 'calendar_update_category';

    protected string $description = 'Use this when the user wants to rename a category the family added, or change its icon or colour. Only family admins can; the five built-in categories can\'t be changed. Only the fields you pass change.';

    protected string $title = 'Update calendar category';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Category id, from calendar_list_categories.')->required(),
            'name' => $schema->string()->description('New name, up to 40 characters.'),
            'color' => $schema->string()->enum(CalendarCategory::COLORS)->description('New colour.'),
            'icon' => $schema->string()->description('New Lucide icon name.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $c = CalendarCategory::find((int) $request->get('id'));
        if (! $c) {
            return $this->notFound('Category');
        }
        if (! $this->user()->can('manageMembers', Family::findOrFail(CurrentFamily::id()))) {
            return Response::error('Only a family admin can change a category.');
        }
        $c->change(CalendarCategory::validated(collect($request->all())->only(['name', 'color', 'icon'])->all() + $c->only(['name', 'color', 'icon']), $c->id));

        return Response::structured(collect(CalendarCategory::forFamily())->firstWhere('id', $c->id));
    }
}
