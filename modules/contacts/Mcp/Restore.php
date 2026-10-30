<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Contacts\ContactsTrash;

class Restore extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_restore';

    protected string $description = 'Use this when the user wants a deleted contact back, with their contact methods and anything other modules kept on them (e.g. their birthday). Takes an id from contacts_list_trash.';

    protected string $title = 'Restore contact';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('Id from contacts_list_trash.')];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $request->validate(['id' => ['required', 'integer']])['id'];
        $path = ContactsTrash::restore($this->user(), 'contact', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
