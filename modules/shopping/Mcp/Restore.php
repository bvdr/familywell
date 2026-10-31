<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Shopping\ShoppingTrash;

// Same restore as Settings > Recently deleted (ShoppingTrash).
class Restore extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_restore';

    protected string $description = 'Use this when the user wants a deleted shopping list or item back. Restoring a list also restores the items deleted with it; restoring an item also restores its deleted list. Takes type and id from shopping_list_trash.';

    protected string $title = 'Restore shopping list or item';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('list or item, from shopping_list_trash.')->enum(['list', 'item'])->required(),
            'id' => $schema->integer()->description('The id from shopping_list_trash.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', ['list', 'item'])], 'id' => ['required', 'integer']]);
        $path = ShoppingTrash::restore($this->user(), $data['type'], (int) $data['id']);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        ShoppingData::changed();

        return Response::structured(['restored' => true, 'type' => $data['type'], 'id' => (int) $data['id'], 'url' => $this->url($path)]);
    }
}
