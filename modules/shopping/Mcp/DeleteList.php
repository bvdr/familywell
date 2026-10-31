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
class DeleteList extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_delete_list';

    protected string $description = 'Use this when the user wants a whole shopping list deleted. The list and its items go to the trash and can be restored for 30 days with shopping_restore. Only the person who created the list can delete it.';

    protected string $title = 'Delete shopping list';

    public function schema(JsonSchema $schema): array
    {
        return ['list_id' => $schema->integer()->description('Shopping list id, from shopping_list_shopping_lists.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
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

        return Response::structured(['deleted' => true, 'type' => 'list', 'id' => $list->id] + ShoppingData::trashInfo($list));
    }
}
