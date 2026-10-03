<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Notifications\Notify;

#[IsDestructive]
class DeleteList extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_delete_list';

    protected string $description = 'Move a shopping list and its items to the trash (restorable for 30 days with shopping_restore). Only the person who created the list can delete it.';

    public function schema(JsonSchema $schema): array
    {
        return ['list_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user();
        $list = ShoppingData::list($user, $request->get('list_id'));
        if (! $list) {
            return $this->notFound('List');
        }
        if ($list->created_by !== $user->id) {
            return Response::error('Only the person who created this list can delete it.'); // same rule as the web UI
        }

        Notify::toAccessOf($list, 'shopping', $user->id, 'Shopping', "{$user->name} deleted list: {$list->name}", '/m/shopping');
        $list->delete();
        ShoppingData::changed();

        return Response::json(['deleted' => true, 'type' => 'list', 'id' => $list->id] + ShoppingData::trashInfo($list));
    }
}
