<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Tasks\Task;

#[IsReadOnly]
class Search extends Tool
{
    protected string $module = 'tasks';

    protected string $name = 'tasks_search';

    protected ?string $app = 'tasks';

    protected string $description = 'Find tasks across the to-do lists this person can see, by text and/or filters (assignee, due date, done).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to match in the task title or note (case-insensitive).'),
            'assigned_to' => $schema->integer()->description('Only tasks assigned to this member id (see get_family).'),
            'due_before' => $schema->string()->format('date')->description('Only tasks due on or before this date (YYYY-MM-DD).'),
            'include_done' => $schema->boolean()->description('Include completed tasks (default false).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['due_before' => ['nullable', 'date'], 'assigned_to' => ['nullable', 'integer']]);
        $q = trim((string) $request->get('query', ''));
        $userId = $this->user()->id;

        $query = Task::query()->with(['assignee', 'list'])
            ->whereHas('list', fn ($l) => $l->visibleTo($userId)) // list-tasks in lists this person can see
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereLike('title', "%{$q}%")->orWhereLike('note', "%{$q}%")))
            ->when($request->get('assigned_to'), fn ($b, $id) => $b->where('assigned_to', $id))
            ->when($request->get('due_before'), fn ($b, $d) => $b->whereNotNull('due_date')->whereDate('due_date', '<=', $d))
            ->when(! $request->get('include_done'), fn ($b) => $b->where('done', false))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'tasks' => $page['items']->map(fn (Task $t) => TaskData::taskPayload($t) + ['list_name' => $t->list?->name])->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
