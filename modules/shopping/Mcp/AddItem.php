<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class AddItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_add_item';

    protected string $description = 'Add an item to a shopping list. The aisle is guessed automatically. If the same item is already on the list (not yet bought) it is still added, and the response says so in `warning` so you can tell the person or update the existing one instead. Optionally set `store` (the primary shop to buy it at; omitted = the list\'s shop) and `alt_stores` (other shops that also sell it).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
            'quantity' => $schema->string()->description('Free text, e.g. "2", "1 kg", "a bunch".'),
            'note' => $schema->string(),
            'store' => $schema->string()->description('Primary shop to buy it at, e.g. "Lidl". Omit to inherit the list\'s shop.'),
            'alt_stores' => $schema->array()->items($schema->string())->description('Other shops that also sell it, e.g. ["Kaufland", "Mega Image"].'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'list_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            // Same rules as the web add sheet (ShoppingController::storeItem / storeShopItem).
            'store' => ['nullable', 'string', 'max:120'],
            'alt_stores' => ['nullable', 'array'],
            'alt_stores.*' => ['string', 'max:120'],
        ]);
        $user = $this->user();
        $list = ShoppingData::list($user, $data['list_id']);
        if (! $list) {
            return $this->notFound('List');
        }

        [$item, $dup] = ShoppingData::addItem($user, $list, $data);

        return Response::structured(ShoppingData::itemPayload($item->fresh()) + ($dup ? [
            'warning' => "{$dup->name} was already on this list (item {$dup->id}".($dup->quantity ? ", quantity {$dup->quantity}" : '').').',
        ] : []));
    }
}
