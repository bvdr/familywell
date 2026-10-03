<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

// Not #[IsIdempotent]: a relative `change` applied twice moves the stock twice.
class AdjustQuantity extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_adjust_quantity';

    protected string $description = 'Record that some of an item was used or restocked. Send `change` (negative = used, positive = restocked, in the item\'s unit) or `quantity` (the new total after counting). Logged in the item\'s history like the app\'s quick update.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required(),
            'change' => $schema->number()->description('Signed amount, e.g. -1 for one used, 6 for six bought.'),
            'quantity' => $schema->number()->description('The new total in stock (instead of change).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'change' => ['nullable', 'numeric', 'required_without:quantity', 'prohibits:quantity'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'required_without:change'],
        ]);
        $item = InventoryData::item($data['item_id']);
        if (! $item) {
            return $this->notFound('Item');
        }

        // The app's quick update takes an absolute amount; a change is resolved against the current stock
        // ("unknown" counts as 0, same as the app's delta) and must not go below zero (its min:0 rule).
        $after = isset($data['quantity']) ? (float) $data['quantity'] : (float) ($item->quantity ?? 0) + (float) $data['change'];
        if ($after < 0) {
            return Response::error("Only {$item->quantity} {$item->unit} of {$item->name} in stock, so it cannot go down by ".abs((float) $data['change']).'.');
        }
        $item->setQuantity($after, $this->user()->id);

        return Response::json(InventoryData::payload($item->fresh()));
    }
}
