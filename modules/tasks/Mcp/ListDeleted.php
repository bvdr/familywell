<?php

namespace Modules\Tasks\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'tasks';

    protected string $access = 'write';

    protected string $name = 'tasks_list_deleted';

    protected string $description = 'What is in the Tasks trash (deleted lists and tasks this person can see), newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $userId = $this->user()->id;
        $lists = TaskList::onlyTrashed()->visibleTo($userId)->get()
            ->map(fn (TaskList $l) => ['type' => 'list', 'id' => $l->id, 'name' => $l->name] + TaskData::trashInfo($l));
        // Tasks whose list (trashed or not) this person can see.
        $tasks = Task::onlyTrashed()->whereHas('list', fn ($q) => $q->withTrashed()->visibleTo($userId))->with(['list' => fn ($q) => $q->withTrashed()])->get()
            ->map(fn (Task $t) => ['type' => 'task', 'id' => $t->id, 'title' => $t->title, 'list' => $t->list->name] + TaskData::trashInfo($t));

        $page = $this->page($lists->concat($tasks)->sortByDesc('deleted_at')->values(), $request);

        return Response::json(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
