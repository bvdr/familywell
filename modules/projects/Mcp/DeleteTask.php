<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

// Same path as ProjectsController::destroyTask (Project::deleteTask): the task is soft-deleted and its
// project link removed. TasksTrash only lists tasks that live in a to-do list, so a project task cannot be
// restored from Settings > Recently deleted today; the description says so instead of promising a restore.
// Whole projects are hard-deleted in the app, so there is deliberately no projects_delete tool.
#[IsDestructive]
class DeleteTask extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_delete_task';

    protected string $description = 'Delete a project task and its sub-tasks, as the Delete button in the app does. It cannot be restored from Recently deleted, so confirm with the person first.';

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $found = ProjectData::task($this->user(), $request->get('task_id'));
        if (! $found) {
            return $this->notFound('Project task');
        }
        [$task, $project] = $found;

        $project->deleteTask($this->user(), $task);
        ProjectData::changed();

        return Response::json(['deleted' => true, 'type' => 'task', 'id' => $task->id, 'project_id' => $project->id]);
    }
}
