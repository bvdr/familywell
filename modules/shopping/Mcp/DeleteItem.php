<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Notifications\Notify;

#[IsDestructive]
class DeleteItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_delete_item';

    protected string $description = 'Move a shopping item to the trash (restorable for 30 days with shopping_restore). To tick it off instead, use shopping_update_item with bought=true.';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $item = ShoppingData::item($user, $request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }

        $item->delete();
        Notify::toAccessOf($item->list, 'shopping', $user->id, $item->list->name, "{$user->name} removed: {$item->name}", "/m/shopping/lists/{$item->shopping_list_id}");
        ShoppingData::changed();

        return Response::structured(['deleted' => true, 'type' => 'item', 'id' => $item->id] + ShoppingData::trashInfo($item));
    }
}
