<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Projects\ProjectDependency;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\Task;

#[IsReadOnly]
class GetProject extends Tool
{
    protected string $module = 'projects';

    protected string $name = 'projects_get';

    protected ?string $app = 'project';

    protected string $description = 'One project with its details, tasks (each with sub-tasks and done state), blockers (dependencies) and linked shopping lists.';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->description('The project id (from projects_list).')->required()];
    }

    public function handle(Request $request): Response
    {
        $project = ProjectData::project($this->user(), $request->get('project_id'));
        if (! $project) {
            return $this->notFound('Project');
        }
        $tasks = ProjectData::topTasks(collect([$project]))[$project->id];
        // Same linked lists the project page shows (bought/total progress).
        $lists = ShoppingList::whereIn('id', $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id'))
            ->withCount(['items', 'items as bought_count' => fn ($q) => $q->where('bought', true)])->get();

        return Response::json(ProjectData::projectPayload($project, $tasks) + [
            'description' => $project->description,
            'owner' => $project->owner ? ['id' => $project->owner->id, 'name' => $project->owner->name] : null,
            'visibility' => $project->visibility(),
            'tasks' => $tasks->map(fn (Task $t) => ProjectData::taskPayload($t, $project, true))->values(),
            'dependencies' => $project->dependencies()->orderBy('position')->orderBy('id')->get()
                ->map(fn (ProjectDependency $d) => ['id' => $d->id, 'name' => $d->name, 'note' => $d->note, 'status' => $d->status])->values(),
            'shopping_lists' => $lists->map(fn (ShoppingList $l) => [
                'id' => $l->id, 'name' => $l->name, 'bought' => $l->bought_count, 'total' => $l->items_count,
            ])->values(),
        ]);
    }
}
