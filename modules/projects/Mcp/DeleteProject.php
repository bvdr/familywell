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

    protected string $description = 'Use this when the user wants to delete a whole project. The project and its tasks go to the trash and can be restored for 30 days with projects_restore; linked shopping and spending lists are kept. Only the person who created it can delete it.';

    protected string $title = 'Delete project';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->description('Project id, from projects_list.')->required()];
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
