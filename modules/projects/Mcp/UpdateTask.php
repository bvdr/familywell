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
class UpdateTask extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_update_task';

    protected string $description = 'Use this when the user wants to tick off or change a project task or sub-task: title, note, due date or assignee. Only the fields you pass change; pass null to clear note, due_date or assigned_to.';

    protected string $title = 'Update project task';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('Project task id, from projects_get.')->required(),
            'title' => $schema->string()->description('New title.'),
            'note' => $schema->string()->description('Short note; null clears it.')->nullable(),
            'due_date' => $schema->string()->description('YYYY-MM-DD; null clears it.')->format('date')->nullable(),
            'assigned_to' => $schema->integer()->description('Member id from get_family; null unassigns.')->nullable(),
            'done' => $schema->boolean()->description('true marks it done, false marks it not done.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(ProjectData::memberIds())],
            'done' => ['sometimes', 'boolean'],
        ]);
        $found = ProjectData::task($this->user(), $data['task_id']);
        if (! $found) {
            return $this->notFound('Project task');
        }
        [$task, $project] = $found;

        $project->updateTask($this->user(), $task, array_intersect_key($data, array_flip(['title', 'note', 'due_date', 'assigned_to', 'done'])));
        ProjectData::changed();

        return Response::structured(ProjectData::taskPayload($task->fresh('assignee'), $project));
    }
}
