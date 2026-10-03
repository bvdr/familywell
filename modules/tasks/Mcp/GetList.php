<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Tasks\Task;

#[IsReadOnly]
class GetList extends Tool
{
    protected string $module = 'tasks';

    protected string $name = 'tasks_get_list';

    protected string $description = 'One to-do list with its tasks (each with its sub-tasks), in list order.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('The list id (from tasks_list_lists).')->required(),
            'include_done' => $schema->boolean()->description('Include completed tasks (default true).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $list = TaskData::list($this->user(), $request->get('list_id'));
        if (! $list) {
            return $this->notFound('List');
        }

        $includeDone = $request->get('include_done', true) !== false;
        $tasks = $list->tasks()->whereNull('parent_id')->with(['subtasks.assignee', 'assignee'])
            ->when(! $includeDone, fn ($q) => $q->where('done', false))
            ->orderBy('position')->orderBy('id')->get();

        return Response::json(TaskData::listPayload($list) + [
            'tasks' => $tasks->map(fn (Task $t) => TaskData::taskPayload($t, true))->values(),
        ]);
    }
}
