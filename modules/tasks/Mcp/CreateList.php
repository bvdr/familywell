<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Tasks\TaskList;

class CreateList extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_create_list';

    protected string $description = 'Use this when the user wants a new to-do list. The list belongs to this person and is visible to the whole family.';

    protected string $title = 'Create to-do list';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('List name.')->required(),
            'color' => $schema->string()->description('Optional colour key (e.g. green, blue, amber).'),
            'icon' => $schema->string()->description('Optional icon key (e.g. list, home, cart).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:20'],
        ]);
        $list = TaskList::createFor($this->user(), $data);
        TaskData::changed();

        return Response::structured(TaskData::listPayload($list));
    }
}
