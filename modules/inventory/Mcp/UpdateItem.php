<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Inventory\InventoryItem;

#[IsIdempotent]
#[IsDestructive]
class UpdateItem extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_update_item';

    protected string $description = 'Use this when the user wants to change a stock item\'s details: name, unit, min or target level, category, location, shop, expiry or notes. Only the fields you send change; send null to clear one. Do not use to record usage or a restock (that is inventory_adjust_quantity).';

    protected string $title = 'Update stock item';

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->integer()->description('Stock item id, from inventory_list.')->required()] + InventoryData::fieldSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $item = InventoryData::item($request->get('item_id'));
        if (! $item) {
            return $this->notFound('Item');
        }

        // The web edit is a full replace (every field posted), so a partial MCP edit is merged over the
        // item's current values first, then goes through the same rules + applyEdit as the form — an
        // omitted field keeps its value instead of being reset to the form's default.
        $current = [
            'name' => $item->name, 'quantity' => $item->quantity, 'unit' => $item->unit,
            'category' => $item->category, 'location' => $item->location, 'shop' => $item->shop,
            'min_quantity' => $item->min_quantity, 'target_quantity' => $item->target_quantity,
            'expires_at' => $item->expires_at?->toDateString(), 'auto_replenish' => $item->auto_replenish,
            'notes' => $item->notes,
        ];
        $data = validator([...$current, ...InventoryData::fieldsFrom($request->all())], InventoryItem::rules())->validate();
        $item->applyEdit($data, $this->user()->id);

        return Response::structured(InventoryData::payload($item->fresh()));
    }
}
