<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notifications\Notify;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingShop;

class AddItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_add_item';

    protected string $description = 'Use this when the user wants something put on a shopping list. The aisle is guessed automatically. If the same item is already on the list unbought it is still added, and `warning` says so, so you can tell the person or update the existing one. Optionally sets the shop to buy it at (`store`) and other shops that sell it (`alt_stores`).';

    protected string $title = 'Add shopping item';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('Shopping list id, from shopping_list_shopping_lists.')->required(),
            'name' => $schema->string()->description('What to buy, e.g. "Milk".')->required(),
            'quantity' => $schema->string()->description('Free text, e.g. "2", "1 kg", "a bunch".'),
            'note' => $schema->string()->description('Short note, e.g. "lactose-free".'),
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

        // Same soft duplicate check as the add sheet in the web UI: warn, never block.
        $dup = ShoppingItem::activeDuplicate($list, $data['name']);
        $item = $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'note' => $data['note'] ?? null,
            'store' => $data['store'] ?? null,
            'alt_stores' => $data['alt_stores'] ?? null,
            'created_by' => $user->id,
        ]);
        // Promote any new store name to a shop so the Shops view stays complete, as the web UI does.
        ShoppingShop::ensure($data['store'] ?? null);
        foreach ($data['alt_stores'] ?? [] as $alt) {
            ShoppingShop::ensure($alt);
        }
        Notify::toAccessOf($list, 'shopping', $user->id, $list->name, "{$user->name} added: {$item->name}", "/m/shopping/lists/{$list->id}");
        ShoppingData::changed();

        return Response::structured(ShoppingData::itemPayload($item->fresh()) + ($dup ? [
            'warning' => "{$dup->name} was already on this list (item {$dup->id}".($dup->quantity ? ", quantity {$dup->quantity}" : '').').',
        ] : []));
    }
}
