<?php

namespace Modules\Inventory;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Deleted stock items in Settings > Recently deleted and the MCP trash tools. Inventory is family-wide
// (no per-item sharing), so the family scope is the whole access rule, same as the Inventory pages.
class InventoryTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return InventoryItem::onlyTrashed()->get()->map(fn (InventoryItem $i) => Trash::row($i, 'inventory_item', $i->name, 'Inventory'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $item = $type === 'inventory_item' ? InventoryItem::onlyTrashed()->find($id) : null;
        if (! $item) {
            return null;
        }
        $item->restore();

        return "/m/inventory/items/{$item->id}";
    }
}
