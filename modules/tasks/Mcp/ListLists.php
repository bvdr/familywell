<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Tasks\TaskList;

#[IsReadOnly]
class ListLists extends Tool
{
    protected string $module = 'tasks';

    protected string $name = 'tasks_list_todo_lists';

    protected string $description = 'Use this when the user asks which to-do lists the family has, or you need a list id. Returns the lists this person can see with how many tasks are open and done. Read-only.';

    protected string $title = 'List to-do lists';

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Include archived lists (default false).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = TaskList::query()->visibleTo($this->user()->id)->with('shareSetting')
            ->withCount(['tasks', 'tasks as done_count' => fn ($q) => $q->where('done', true)])
            ->when(! $request->get('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'lists' => $page['items']->map(fn (TaskList $l) => TaskData::listPayload($l) + [
                'open_count' => $l->tasks_count - $l->done_count,
                'done_count' => $l->done_count,
            ])->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
