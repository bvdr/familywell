<?php

namespace Modules\Inventory\Mcp;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Inventory\InventoryItem;

// Shared lookups + payload shape for the Inventory MCP tools. Stock is family-wide (no per-item sharing:
// every member sees and edits the same items, like the web UI), so the BelongsToFamily scope under
// CurrentFamily IS the access rule — find() simply returns null for another family's item.
class InventoryData
{
    public static function item(mixed $id): ?InventoryItem
    {
        return InventoryItem::find((int) $id);
    }

    /** Compact item shape. category/location are KEYS (what the write tools accept back). */
    public static function payload(InventoryItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'state' => $i->state(), // expired | empty | expiring | low | unknown | good
            'quantity' => $i->quantity, // null = never measured (the "unknown" state)
            'unit' => $i->unit,
            'min_quantity' => $i->min_quantity,
            'target_quantity' => $i->target_quantity,
            'category' => $i->category,
            'location' => $i->location,
            'shop' => $i->shop,
            'expires_on' => $i->expires_at?->toDateString(),
            'auto_replenish' => $i->auto_replenish,
            'on_shopping_list' => $i->onShoppingList(),
            'url' => self::url("/m/inventory/items/{$i->id}"),
        ];
    }

    /**
     * The editable item fields, shared by inventory_add_item and inventory_update_item. Names match
     * InventoryItem::rules() except expires_on (-> expires_at), so tool input validates with the exact
     * rules the web form uses.
     */
    public static function fieldSchema($schema): array
    {
        return [
            'name' => $schema->string()->description('Item name, e.g. "Olive oil".'),
            'quantity' => $schema->number()->description('How much is in stock now, in `unit`. Omit/null if unknown.'),
            'unit' => $schema->string()->description('Unit the quantity is counted in.')->enum(InventoryItem::UNITS),
            'min_quantity' => $schema->number()->description('At or below this the item is "low" (0 = no low alert).'),
            'target_quantity' => $schema->number()->description('The "full" level a purchase tops it up to.'),
            'category' => $schema->string()->description('Category key or name; a new name creates the category.'),
            'location' => $schema->string()->description('Location key or name; a new name creates the location.'),
            'shop' => $schema->string()->description('Where it is usually bought (shop name).'),
            'expires_on' => $schema->string()->format('date')->description('YYYY-MM-DD.'),
            'auto_replenish' => $schema->boolean()->description('Include in "add all low items to Shopping" (default true).'),
            'notes' => $schema->string()->description('Free-text notes.'),
        ];
    }

    /** Tool arguments -> InventoryItem::rules() field names (only the ones actually sent). */
    public static function fieldsFrom(array $args): array
    {
        if (array_key_exists('expires_on', $args)) {
            $args['expires_at'] = $args['expires_on'];
        }

        return array_intersect_key($args, InventoryItem::rules());
    }

    public static function shoppingEnabled(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('shopping', (int) CurrentFamily::id());
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
