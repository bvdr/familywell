<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteProject extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_delete';

    protected string $description = 'Move a whole project and its tasks to the trash (restorable for 30 days with projects_restore). Only the person who created it can. Linked shopping and spending lists are not deleted.';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $project = ProjectData::project($user, $request->get('project_id'));
        if (! $project) {
            return $this->notFound('Project');
        }
        if ($project->created_by !== $user->id) {
            return Response::error('Only the person who created this project can delete it.'); // same rule as the web UI
        }
        $project->deleteFor($user);
        ProjectData::changed();

        return Response::structured(['deleted' => true, 'project_id' => $project->id, 'name' => $project->name]);
    }
}
