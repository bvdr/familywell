<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

// Same path as ProjectsController::destroyTask (Project::deleteTask): the task goes to the trash and keeps
// its project link, so projects_restore / Settings > Recently deleted put it back in the project.
#[IsDestructive]
class DeleteTask extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_delete_task';

    protected string $description = 'Use this when the user wants to delete a task (and its sub-tasks) from a project. It goes to the trash and can be restored for 30 days with projects_restore.';

    protected string $title = 'Delete project task';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->description('Project task id, from projects_get.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $found = ProjectData::task($this->user(), $request->get('task_id'));
        if (! $found) {
            return $this->notFound('Project task');
        }
        [$task, $project] = $found;

        $project->deleteTask($this->user(), $task);
        ProjectData::changed();

        return Response::structured(['deleted' => true, 'type' => 'task', 'id' => $task->id, 'project_id' => $project->id]);
    }
}
