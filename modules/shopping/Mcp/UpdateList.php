<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Shopping\ShoppingShop;

#[IsIdempotent]
#[IsDestructive]
class UpdateList extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_update_list';

    protected string $description = 'Use this when the user wants to rename a shopping list, change its store, or archive or unarchive it. Only the fields you pass change.';

    protected string $title = 'Update shopping list';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('Shopping list id, from shopping_list_shopping_lists.')->required(),
            'name' => $schema->string()->description('New name.'),
            'store' => $schema->string()->description('Shop name, e.g. "Lidl"; null clears it.')->nullable(),
            'archived' => $schema->boolean()->description('true archives the list, false brings it back.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'store' => ['sometimes', 'nullable', 'string', 'max:120'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $list = ShoppingData::list($this->user(), $data['list_id']);
        if (! $list) {
            return $this->notFound('List');
        }

        if (array_key_exists('archived', $data)) {
            $list->archived_at = $data['archived'] ? now() : null;
        }
        $list->fill(array_intersect_key($data, array_flip(['name', 'store'])))->save();
        if (array_key_exists('store', $data)) {
            ShoppingShop::ensure($data['store']); // same as the web UI: a list's store becomes a shop
        }
        ShoppingData::changed();

        return Response::structured(ShoppingData::listPayload($list));
    }
}
