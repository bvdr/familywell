<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notifications\Notify;
use Modules\Shopping\ShoppingItem;

class AddItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_add_item';

    protected string $description = 'Add an item to a shopping list. The aisle is guessed automatically. If the same item is already on the list (not yet bought) it is still added, and the response says so in `warning` so you can tell the person or update the existing one instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'quantity' => $schema->string()->description('Free text, e.g. "2", "1 kg", "a bunch".'),
            'note' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $this->user();
        $list = ShoppingData::list($user, $data['list_id']);
        if (! $list) {
            return $this->notFound('List');
        }

        // Same soft duplicate check as the add sheet in the web UI: warn, never block.
        $dup = ShoppingItem::activeDuplicate($list, $data['name']);
        $item = $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $user->id,
        ]);
        Notify::toAccessOf($list, 'shopping', $user->id, $list->name, "{$user->name} added: {$item->name}", "/m/shopping/lists/{$list->id}");
        ShoppingData::changed();

        return Response::structured(ShoppingData::itemPayload($item->fresh()) + ($dup ? [
            'warning' => "{$dup->name} was already on this list (item {$dup->id}".($dup->quantity ? ", quantity {$dup->quantity}" : '').').',
        ] : []));
    }
}
