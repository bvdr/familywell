<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Birthdays\BirthdaysTrash;

class Restore extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_restore';

    protected string $description = 'Use this when the user wants a removed birthday back. Takes an id from birthdays_list_trash.';

    protected string $title = 'Restore birthday';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('Id from birthdays_list_trash.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $request->validate(['id' => ['required', 'integer']])['id'];
        $path = BirthdaysTrash::restore($this->user(), 'birthday', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
