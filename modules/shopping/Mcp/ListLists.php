<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Shopping\ShoppingList;

#[IsReadOnly]
class ListLists extends Tool
{
    protected string $module = 'shopping';

    protected string $name = 'shopping_list_lists';

    protected string $description = 'List the family\'s shopping lists this person can see, with how many items are still to buy.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Include archived lists (default false).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = ShoppingList::query()->visibleTo($this->user()->id)->with('shareSetting')
            ->withCount(['items', 'items as to_buy_count' => fn ($q) => $q->where('bought', false)])
            ->when(! $request->get('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'lists' => $page['items']->map(fn (ShoppingList $l) => ShoppingData::listPayload($l) + [
                'to_buy_count' => $l->to_buy_count,
                'bought_count' => $l->items_count - $l->to_buy_count,
            ])->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
