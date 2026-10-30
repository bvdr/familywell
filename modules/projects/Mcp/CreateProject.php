<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Projects\Project;

class CreateProject extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_create';

    protected string $description = 'Use this when the user wants to start a family project (a renovation, a move, a party...). The project belongs to this person and is visible to the whole family.';

    protected string $title = 'Create project';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Project name, e.g. "Kitchen renovation".')->required(),
            'description' => $schema->string()->description('Up to 2000 characters.'),
            'status' => $schema->string()->enum(ProjectData::STATUSES)->description('Default planning.'),
            'progress' => $schema->integer()->description('Percent done, 0-100 (default 0).'),
            'target_start' => $schema->string()->format('date')->description('YYYY-MM-DD.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Same rules as ProjectsController::storeProject.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:'.implode(',', ProjectData::STATUSES)],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'target_start' => ['nullable', 'date'],
        ]);
        $project = Project::createFor($this->user(), $data);
        ProjectData::changed();

        return Response::structured(ProjectData::projectPayload($project, collect()));
    }
}
