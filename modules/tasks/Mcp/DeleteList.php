<?php

namespace Modules\Tasks\Mcp;

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
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_delete_list';

    protected string $description = 'Move a to-do list and its tasks to the trash (restorable for 30 days with tasks_restore). Only the person who created the list can delete it.';

    public function schema(JsonSchema $schema): array
    {
        return ['list_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $list = TaskData::list($user, $request->get('list_id'));
        if (! $list) {
            return $this->notFound('List');
        }
        if ($list->created_by !== $user->id) {
            return Response::error('Only the person who created this list can delete it.'); // same rule as the web UI
        }

        Notify::toAccessOf($list, 'tasks', $user->id, 'Tasks', "{$user->name} deleted list: {$list->name}", '/m/tasks');
        $list->delete();
        TaskData::changed();

        return Response::structured(['deleted' => true, 'type' => 'list', 'id' => $list->id] + TaskData::trashInfo($list));
    }
}
