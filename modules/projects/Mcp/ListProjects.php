<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Projects\Project;

#[IsReadOnly]
class ListProjects extends Tool
{
    protected string $module = 'projects';

    protected string $name = 'projects_list';

    protected string $description = 'List the family\'s projects this person can see, with status, progress and how many tasks are done.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(ProjectData::STATUSES)->description('Only projects with this status.'),
            'include_archived' => $schema->boolean()->description('Include archived projects (default false).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', ProjectData::STATUSES)]]);
        // visibleTo = the same "family-visible or shared with me" filter the projects index applies in PHP.
        $query = Project::query()->visibleTo($this->user()->id)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->get('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')->latest();
        $page = $this->page($query, $request);
        $tasks = ProjectData::topTasks($page['items']);

        return Response::structured([
            'projects' => $page['items']->map(fn (Project $p) => ProjectData::projectPayload($p, $tasks[$p->id]))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
