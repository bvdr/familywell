<?php

namespace Modules\Shopping\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateItem extends Tool
{
    protected string $module = 'shopping';

    protected string $access = 'write';

    protected string $name = 'shopping_update_item';

    protected string $description = 'Change a shopping item (name, quantity, note) or tick it off as bought / un-tick it. Only the fields you pass change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'quantity' => $schema->string()->nullable(),
            'note' => $schema->string()->nullable(),
            'bought' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bought' => ['sometimes', 'boolean'],
        ]);
        $item = ShoppingData::item($this->user(), $data['item_id']);
        if (! $item) {
            return $this->notFound('Item');
        }

        $wasBought = $item->bought;
        $item->update(array_intersect_key($data, array_flip(['name', 'quantity', 'note', 'bought'])));
        // "Shopping done" ping + Inventory restock hook, exactly as when ticked off in the app.
        $item->settleBought($wasBought, $this->user());
        ShoppingData::changed();

        return Response::json(ShoppingData::itemPayload($item));
    }
}
