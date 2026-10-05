<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class AddTask extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_add_task';

    protected string $description = 'Add a task to a project, optionally as a sub-task (one level deep) of one of its tasks, with a due date and an assignee.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'note' => $schema->string()->description('Short note (max 255 chars).'),
            'due_date' => $schema->string()->format('date')->description('YYYY-MM-DD.'),
            'assigned_to' => $schema->integer()->description('Member id from get_family.'),
            'parent_id' => $schema->integer()->description('Make it a sub-task of this top-level task of the same project.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::in(ProjectData::memberIds())],
            'parent_id' => ['nullable', 'integer'],
        ]);
        $user = $this->user();
        $project = ProjectData::project($user, $data['project_id']);
        if (! $project) {
            return $this->notFound('Project');
        }
        // Checked up front for a readable error; Project::addTask enforces the same rule with a 404.
        if (! empty($data['parent_id']) && ! $project->hasTopTask((int) $data['parent_id'])) {
            return Response::error('parent_id must be a top-level task of the same project (sub-tasks are one level deep).');
        }

        $task = $project->addTask($user, $data);
        ProjectData::changed();

        return Response::structured(ProjectData::taskPayload($task->load('assignee'), $project));
    }
}
