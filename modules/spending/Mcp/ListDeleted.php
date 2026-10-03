<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Spending\SpendingTrash;

// Same list as Settings > Recently deleted (SpendingTrash).
#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_list_deleted';

    protected string $description = 'Expenses in the trash (from lists this person can see), newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $page = $this->page(Trash::sorted(SpendingTrash::list($this->user())), $request);

        return Response::json(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
