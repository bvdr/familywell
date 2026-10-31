<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Shopping\ShoppingShop;

#[IsIdempotent]
#[IsDestructive]
class UpdateItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_update_item';

    protected string $description = 'Use this when the user wants to tick a shopping item off as bought (or un-tick it), or change its name, quantity, note or shops. Only the fields you pass change. `store` is the shop to buy it at (null = the list\'s shop); `alt_stores` replaces the other shops ([] or null clears them).';

    protected string $title = 'Update shopping item';

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->description('Shopping item id, from shopping_get_list or shopping_search.')->required(),
            'name' => $schema->string()->description('New name.'),
            'quantity' => $schema->string()->description('Free text, e.g. "2" or "1 kg"; null clears it.')->nullable(),
            'note' => $schema->string()->description('Short note; null clears it.')->nullable(),
            'bought' => $schema->boolean()->description('true ticks it off as bought, false puts it back on the list.'),
            'store' => $schema->string()->nullable()->description('Primary shop, e.g. "Lidl". null = inherit the list\'s shop.'),
            'alt_stores' => $schema->array()->items($schema->string())->nullable()->description('Other shops that also sell it. Replaces the current set.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bought' => ['sometimes', 'boolean'],
            // Same rules as the web item editor (ShoppingController::updateItem).
            'store' => ['sometimes', 'nullable', 'string', 'max:120'],
            'alt_stores' => ['sometimes', 'nullable', 'array'],
            'alt_stores.*' => ['string', 'max:120'],
        ]);
        $item = ShoppingData::item($this->user(), $data['item_id']);
        if (! $item) {
            return $this->notFound('Item');
        }

        $wasBought = $item->bought;
        $item->update(array_intersect_key($data, array_flip(['name', 'quantity', 'note', 'bought', 'store', 'alt_stores'])));
        // Promote any new store name to a shop so the Shops view stays complete, as the web UI does.
        ShoppingShop::ensure($data['store'] ?? null);
        foreach ($data['alt_stores'] ?? [] as $alt) {
            ShoppingShop::ensure($alt);
        }
        // "Shopping done" ping + Inventory restock hook, exactly as when ticked off in the app.
        $item->settleBought($wasBought, $this->user());
        ShoppingData::changed();

        return Response::structured(ShoppingData::itemPayload($item));
    }
}
