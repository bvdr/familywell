<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class UpdateProject extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_update';

    protected string $description = 'Use this when the user wants to change a project: name, description, status, progress, owner, target start, what it is waiting on, or to archive it. Only the fields you pass change; pass null to clear description, target_start or waiting_on.';

    protected string $title = 'Update project';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project id, from projects_list.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('Up to 2000 characters; null clears it.')->nullable(),
            'status' => $schema->string()->description('New status.')->enum(ProjectData::STATUSES),
            'progress' => $schema->integer()->description('Percent done, 0-100.'),
            'owner_id' => $schema->integer()->description('Member id from get_family.'),
            'target_start' => $schema->string()->description('YYYY-MM-DD; null clears it.')->format('date')->nullable(),
            'waiting_on' => $schema->string()->nullable()->description('What a blocked project waits for.'),
            'archived' => $schema->boolean()->description('true archives the project, false brings it back.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // ProjectsController::updateProject's rules, minus visibility (sharing is managed in the app) and
        // with owner_id limited to family members (the web form only offers members).
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:'.implode(',', ProjectData::STATUSES)],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'owner_id' => ['sometimes', 'integer', Rule::in(ProjectData::memberIds())],
            'target_start' => ['sometimes', 'nullable', 'date'],
            'waiting_on' => ['sometimes', 'nullable', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $project = ProjectData::project($this->user(), $data['project_id']);
        if (! $project) {
            return $this->notFound('Project');
        }

        $project->updateFrom($this->user(), $data);
        ProjectData::changed();

        return Response::structured(ProjectData::projectPayload($project, ProjectData::topTasks(collect([$project]))[$project->id]));
    }
}
