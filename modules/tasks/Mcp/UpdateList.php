<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class UpdateList extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_update_list';

    protected string $description = 'Use this when the user wants to rename, recolour, or archive or unarchive a to-do list. Only the fields you pass change.';

    protected string $title = 'Update to-do list';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('To-do list id, from tasks_list_todo_lists.')->required(),
            'name' => $schema->string()->description('New name.'),
            'color' => $schema->string()->description('Colour key, e.g. green, blue, amber.'),
            'icon' => $schema->string()->description('Icon key, e.g. list, home, cart.'),
            'archived' => $schema->boolean()->description('true archives the list, false brings it back.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:20'],
            'icon' => ['sometimes', 'string', 'max:20'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $list = TaskData::list($this->user(), $data['list_id']);
        if (! $list) {
            return $this->notFound('List');
        }

        if (array_key_exists('archived', $data)) {
            $list->archived_at = $data['archived'] ? now() : null;
        }
        $list->fill(array_intersect_key($data, array_flip(['name', 'color', 'icon'])))->save();
        TaskData::changed();

        return Response::structured(TaskData::listPayload($list));
    }
}
