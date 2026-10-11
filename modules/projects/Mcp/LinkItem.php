<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Projects\ProjectLinkables;

#[IsIdempotent]
class LinkItem extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_link';

    protected string $description = 'Link an existing spending list or contact to a project (it then shows on the project page and in projects_get). Linking twice is harmless. The list must be one you can see.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['spend_list', 'contact'])->required()->description('spend_list = a Spending list (id from spending tools), contact = a person (id from contacts tools).'),
            'id' => $schema->integer()->required()->description('The spending list id or contact id.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'type' => ['required', 'in:spend_list,contact'],
            'id' => ['required', 'integer'],
        ]);
        $project = ProjectData::project($this->user(), $data['project_id']);
        if (! $project) {
            return $this->notFound('Project');
        }
        $type = $data['type'] === 'spend_list' ? ProjectLinkables::SPEND : ProjectLinkables::CONTACT;
        // The token must also be allowed into the linked module: linking is a way to surface its data.
        $module = ProjectLinkables::MODULES[$type];
        if (! $this->user()->tokenCan("{$module}:read") || ! ProjectLinkables::enabled($type)) {
            return Response::error("This connection has no access to {$module}. Reconnect and allow it to link {$data['type']}s.");
        }

        if (! ProjectLinkables::link($project, $this->user(), $type, $data['id'])) {
            return $this->notFound($data['type'] === 'spend_list' ? 'Spending list' : 'Contact');
        }
        ProjectData::changed();

        return Response::structured(['project_id' => $project->id, 'type' => $data['type'], 'id' => $data['id'], 'linked' => true]);
    }
}
