<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Birthdays\BirthdaysTrash;

// Same list as Settings > Recently deleted (BirthdaysTrash). Write access, like every module's trash tools:
// it only exists to pick something to restore.
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_list_trash';

    protected string $description = 'Use this when the user wants to find a birthday they removed, to bring it back. Lists removed birthdays (of contacts that still exist), newest first, with the date each is deleted for good. A birthday removed together with its contact comes back with contacts_restore. Read-only.';

    protected string $title = 'List deleted birthdays';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(BirthdaysTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
