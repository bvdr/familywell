<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Shopping\ShoppingItem;

#[IsReadOnly]
class GetList extends Tool
{
    protected string $module = 'shopping';

    protected string $name = 'shopping_get_list';

    protected string $description = 'One shopping list with its items (name, quantity, note, aisle, bought).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'include_bought' => $schema->boolean()->description('Include items already bought (default true).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $list = ShoppingData::list($this->user(), $request->get('list_id'));
        if (! $list) {
            return $this->notFound('List');
        }

        $items = $list->items()
            ->when($request->get('include_bought', true) === false, fn ($q) => $q->where('bought', false))
            ->orderBy('bought')->orderBy('position')->orderBy('id')->get();

        return Response::json(ShoppingData::listPayload($list) + [
            'items' => $items->map(fn (ShoppingItem $i) => ShoppingData::itemPayload($i))->values(),
        ]);
    }
}
