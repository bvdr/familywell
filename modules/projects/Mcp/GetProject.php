<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Projects\ProjectDependency;
use Modules\Projects\ProjectLinkables;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\Task;

#[IsReadOnly]
class GetProject extends Tool
{
    protected string $module = 'projects';

    protected string $name = 'projects_get';

    protected ?string $app = 'project';

    protected string $description = 'Use this when the user asks about one project in detail. Returns its details, tasks (with sub-tasks and done state), blockers, and the linked shopping lists, spending lists and contacts this connection may read. Read-only.';

    protected string $title = 'Get project';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->description('The project id (from projects_list).')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $project = ProjectData::project($this->user(), $request->get('project_id'));
        if (! $project) {
            return $this->notFound('Project');
        }
        $tasks = ProjectData::topTasks(collect([$project]))[$project->id];

        return Response::structured(ProjectData::projectPayload($project, $tasks) + [
            'description' => $project->description,
            'owner' => $project->owner ? ['id' => $project->owner->id, 'name' => $project->owner->name] : null,
            'visibility' => $project->visibility(),
            'tasks' => $tasks->map(fn (Task $t) => ProjectData::taskPayload($t, $project, true))->values(),
            'dependencies' => $project->dependencies()->orderBy('position')->orderBy('id')->get()
                ->map(fn (ProjectDependency $d) => ['id' => $d->id, 'name' => $d->name, 'note' => $d->note, 'status' => $d->status])->values(),
        ] + $this->shoppingLists($project) + $this->linked($project));
    }

    /**
     * Same linked lists the project page shows (bought/total progress), but only when this token may read
     * Shopping and the module is on: like spend_lists/contacts, a projects-only connection must not see
     * another module's data through a project.
     */
    private function shoppingLists($project): array
    {
        if (! $this->user()->tokenCan('shopping:read') || ! app(ModuleRegistry::class)->isEnabled('shopping', (int) CurrentFamily::id())) {
            return [];
        }
        $lists = ShoppingList::whereIn('id', $project->links()->where('linkable_type', 'shopping_list')->pluck('linkable_id'))
            ->withCount(['items', 'items as bought_count' => fn ($q) => $q->where('bought', true)])->get();

        return ['shopping_lists' => $lists->map(fn (ShoppingList $l) => [
            'id' => $l->id, 'name' => $l->name, 'bought' => $l->bought_count, 'total' => $l->items_count,
        ])->values()];
    }

    /**
     * Linked spending lists + contacts, each only when this token may read that module: a projects-only
     * connection must not see spending totals or phone numbers through a project. Same payloads as the
     * project page (ProjectLinkables), plus a url.
     */
    private function linked($project): array
    {
        $out = [];
        foreach ([ProjectLinkables::SPEND => 'spend_lists', ProjectLinkables::CONTACT => 'contacts'] as $type => $key) {
            if (! $this->user()->tokenCan(ProjectLinkables::MODULES[$type].':read')) {
                continue;
            }
            $items = ProjectLinkables::linked($project, $this->user(), $type);
            if ($items !== null) {
                $out[$key] = $items->map(fn (array $i) => $i + ['url' => $this->url($type === ProjectLinkables::SPEND
                    ? "/m/spending/events/{$i['id']}" : "/m/contacts?person={$i['id']}")])->values();
            }
        }

        return $out;
    }
}
