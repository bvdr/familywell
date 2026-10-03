<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTask extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_update_task';

    protected string $description = 'Change a project task or sub-task: title, note, due date, assignee, or mark it done/not done. Only the fields you pass change; pass null to clear note, due_date or assigned_to.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'note' => $schema->string()->nullable(),
            'due_date' => $schema->string()->format('date')->nullable(),
            'assigned_to' => $schema->integer()->nullable(),
            'done' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
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

        return Response::json(ProjectData::taskPayload($task->fresh('assignee'), $project));
    }
}
