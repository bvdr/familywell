<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Shopping\ShoppingItem;

#[IsReadOnly]
class Search extends Tool
{
    protected string $module = 'shopping';

    protected string $name = 'shopping_search';

    protected ?string $app = 'shopping';

    protected string $description = 'Use this when the user asks whether something is already on a shopping list (e.g. "is milk on any list?"). Searches items across every list this person can see. Read-only.';

    protected string $title = 'Search shopping items';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to match in the item name or note (case-insensitive).')->required(),
            'include_bought' => $schema->boolean()->description('Include items already bought (default false).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $q = trim((string) $request->validate(['query' => ['required', 'string', 'max:255']])['query']);
        $userId = $this->user()->id;

        $query = ShoppingItem::query()->with('list')
            ->whereHas('list', fn ($l) => $l->visibleTo($userId))
            ->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('note', "%{$q}%"))
            ->when(! $request->get('include_bought'), fn ($b) => $b->where('bought', false))
            ->orderBy('bought')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'items' => $page['items']->map(fn (ShoppingItem $i) => ShoppingData::itemPayload($i) + ['list_name' => $i->list?->name])->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
