<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Inventory\InventoryCategory;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryLocation;

#[IsReadOnly]
class ListItems extends Tool
{
    protected string $module = 'inventory';

    protected string $name = 'inventory_list';

    protected ?string $app = 'inventory';

    protected string $description = 'The family\'s household stock (pantry, cleaning, bathroom...), with each item\'s quantity + unit and derived state. Filter by state, category, location or a name search. Also returns the family\'s category and location keys.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->enum([...InventoryItem::STATES, 'needs_attention', 'needs_replenishing'])
                ->description('needs_attention = expired/empty/expiring/low; needs_replenishing = low/empty (what buying more fixes).'),
            'category' => $schema->string()->description('Category key.'),
            'location' => $schema->string()->description('Location key.'),
            'search' => $schema->string()->description('Part of the item name.'),
        ] + $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $q = InventoryItem::query()->orderBy('name');
        foreach (['category', 'location'] as $col) {
            if ($v = $request->get($col)) {
                $q->where($col, $v);
            }
        }
        if ($s = trim((string) $request->get('search'))) {
            $q->where('name', 'like', '%'.addcslashes($s, '%_\\').'%');
        }

        // State is derived in PHP (one source of truth, InventoryItem::state()), so it filters the
        // collection, not the query. ponytail: in-memory, fine for household-sized stock.
        $state = $request->get('state');
        $items = $q->get()->filter(fn (InventoryItem $i) => match ($state) {
            null, '' => true,
            'needs_attention' => $i->needsAttention(),
            'needs_replenishing' => $i->wantsReplenishment(),
            default => $i->state() === $state,
        })->values();
        $page = $this->page($items, $request);

        return Response::structured([
            'items' => $page['items']->map(fn (InventoryItem $i) => InventoryData::payload($i))->values(),
            'next_cursor' => $page['next_cursor'],
            'categories' => InventoryCategory::forFamily()->pluck('name', 'key'),
            'locations' => InventoryLocation::forFamily()->pluck('name', 'key'),
        ]);
    }
}
