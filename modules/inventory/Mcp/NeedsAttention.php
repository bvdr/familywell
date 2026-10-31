<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Inventory\InventoryItem;

#[IsReadOnly]
class NeedsAttention extends Tool
{
    protected string $module = 'inventory';

    protected string $name = 'inventory_list_needs_attention';

    protected ?string $app = 'inventory';

    protected string $description = 'Use this when the user asks what is running out, low, expiring or due a recount. Returns stock grouped into empty, low, expiring (expired ones included, soonest first) and stale (stocked but not updated in a while); each item is in one group only. Read-only.';

    protected string $title = 'List stock needing attention';

    public function handle(Request $request): Response|ResponseFactory
    {
        $buckets = InventoryItem::attentionBuckets(InventoryItem::query()->orderBy('name')->get());
        $out = array_map(fn (array $rows) => array_map(fn (InventoryItem $i) => InventoryData::payload($i), $rows), $buckets);

        return Response::structured($out + [
            'total' => array_sum(array_map('count', $buckets)),
            'url' => InventoryData::url('/m/inventory/needs-attention'),
        ]);
    }
}
