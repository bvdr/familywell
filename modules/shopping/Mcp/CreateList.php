<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notifications\Notify;
use Modules\Shopping\ShoppingList;

class CreateList extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_create_list';

    protected string $description = 'Use this when the user wants a new shopping list, optionally for one store. The list belongs to this person and is visible to the whole family.';

    protected string $title = 'Create shopping list';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('List name, e.g. "Weekly groceries".')->required(),
            'store' => $schema->string()->description('Optional store/shop name (e.g. "Lidl").'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $this->user();
        $list = ShoppingList::createFor($user, $data);
        Notify::toAccessOf($list, 'shopping', $user->id, 'New shopping list', "{$user->name} started: {$list->name}", "/m/shopping/lists/{$list->id}");
        ShoppingData::changed();

        return Response::structured(ShoppingData::listPayload($list));
    }
}
