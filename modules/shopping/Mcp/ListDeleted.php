<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_list_deleted';

    protected string $description = 'What is in the Shopping trash (deleted lists and items this person can see), newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $userId = $this->user()->id;
        $lists = ShoppingList::onlyTrashed()->visibleTo($userId)->get()
            ->map(fn (ShoppingList $l) => ['type' => 'list', 'id' => $l->id, 'name' => $l->name] + ShoppingData::trashInfo($l));
        $items = ShoppingItem::onlyTrashed()->whereHas('list', fn ($q) => $q->withTrashed()->visibleTo($userId))->with(['list' => fn ($q) => $q->withTrashed()])->get()
            ->map(fn (ShoppingItem $i) => ['type' => 'item', 'id' => $i->id, 'name' => $i->name, 'list' => $i->list->name] + ShoppingData::trashInfo($i));

        $page = $this->page($lists->concat($items)->sortByDesc('deleted_at')->values(), $request);

        return Response::json(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
