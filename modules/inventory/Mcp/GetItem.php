<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Inventory\InventoryLog;

#[IsReadOnly]
class GetItem extends Tool
{
    protected string $module = 'inventory';

    protected string $name = 'inventory_get';

    protected string $description = 'One stock item in full, with its notes, what a purchase would top it up to, and its recent quantity history (added / consumed / adjusted / purchased).';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $item = InventoryData::item($request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }

        return Response::json(InventoryData::payload($item) + [
            'notes' => $item->notes,
            'replenish_to' => $item->replenishTarget(),
            // Same 30-row window the item detail page shows.
            'history' => $item->logs()->with('user:id,name')->limit(30)->get()->map(fn (InventoryLog $l) => [
                'type' => $l->type,
                'change' => $l->delta, // signed, in the item's unit; null when the amount was unknown
                'by' => $l->user?->name,
                'at' => $l->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
