<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Inventory\InventoryItem;

#[IsReadOnly]
class NeedsAttention extends Tool
{
    protected string $module = 'inventory';

    protected string $name = 'inventory_needs_attention';

    protected string $description = 'What needs doing in the household stock, the same buckets as the app\'s Needs Attention screen: empty, low, expiring (incl. already expired, soonest first) and stale (stocked but not updated in a while). Each item is in one bucket only.';

    public function handle(Request $request): Response
    {
        $buckets = InventoryItem::attentionBuckets(InventoryItem::query()->orderBy('name')->get());
        $out = array_map(fn (array $rows) => array_map(fn (InventoryItem $i) => InventoryData::payload($i), $rows), $buckets);

        return Response::json($out + [
            'total' => array_sum(array_map('count', $buckets)),
            'url' => InventoryData::url('/m/inventory/needs-attention'),
        ]);
    }
}
