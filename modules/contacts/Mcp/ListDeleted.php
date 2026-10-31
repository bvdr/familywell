<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Contacts\ContactsTrash;

// Same list as Settings > Recently deleted (ContactsTrash). Write access, like every module's trash tools:
// it only exists to pick something to restore.
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_list_trash';

    protected string $description = 'Use this when the user wants to find a contact they deleted, to bring it back. Lists contacts in the trash, newest first, with the date each is deleted for good. Read-only.';

    protected string $title = 'List deleted contacts';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(ContactsTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
