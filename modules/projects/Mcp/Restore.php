<?php

namespace Modules\Projects\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Projects\ProjectsTrash;

// Same restore as Settings > Recently deleted (TasksTrash).
class Restore extends Tool
{
    protected string $module = 'projects';

    protected string $access = 'write';

    protected string $name = 'projects_restore';

    protected string $description = 'Use this when the user wants a deleted project or project task back. Restoring a project also restores the tasks deleted with it; restoring a sub-task also restores its deleted parent. Takes type and id from projects_list_trash.';

    protected string $title = 'Restore project or task';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('project or task, from projects_list_trash.')->enum(['project', 'task'])->required(),
            'id' => $schema->integer()->description('The id from projects_list_trash.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', ['project', 'task'])], 'id' => ['required', 'integer']]);
        $path = ProjectsTrash::restore($this->user(), $data['type'], (int) $data['id']);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        ProjectData::changed();

        return Response::structured(['restored' => true, 'type' => $data['type'], 'id' => (int) $data['id'], 'url' => $this->url($path)]);
    }
}
