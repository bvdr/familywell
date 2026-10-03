<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateList extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_update_list';

    protected string $description = 'Rename, recolour, or archive/unarchive a to-do list. Only the fields you pass change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'color' => $schema->string(),
            'icon' => $schema->string(),
            'archived' => $schema->boolean()->description('true archives the list, false brings it back.'),
        ];
    }

    public function handle(Request $request): Response
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

        return Response::json(TaskData::listPayload($list));
    }
}
