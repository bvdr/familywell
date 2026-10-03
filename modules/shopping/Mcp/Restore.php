<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

class Restore extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_restore';

    protected string $description = 'Bring a shopping list or item back from the trash. Restoring a list brings back the items deleted with it; restoring an item also restores its deleted list.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['list', 'item'])->required(),
            'id' => $schema->integer()->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => ['required', 'in:list,item'], 'id' => ['required', 'integer']]);
        $model = $data['type'] === 'list'
            ? ShoppingList::onlyTrashed()->find($data['id'])
            : ShoppingItem::onlyTrashed()->with(['list' => fn ($q) => $q->withTrashed()])->find($data['id']);
        $list = $model instanceof ShoppingItem ? $model->list : $model;
        if (! $model || ! $list || app(ShareAccess::class)->roleFor($this->user(), $list) === null) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        $model->restore();
        ShoppingData::changed();

        return Response::json(['restored' => true, 'type' => $data['type'], 'id' => $model->id, 'url' => rtrim((string) config('app.url'), '/')."/m/shopping/lists/{$list->id}"]);
    }
}
