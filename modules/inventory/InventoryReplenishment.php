<?php

namespace Modules\Inventory;

use App\Events\ShoppingChanged;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Collection;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

/**
 * The Inventory <-> Shopping core loop, in one place so the controller endpoints and the
 * `shopping.item.bought` hook listener share exactly the same rules.
 *
 * Inventory -> Shopping: a short item is surfaced as a shopping row on the family's default list, and the
 * inventory item keeps a pointer to that open row (shopping_item_id). That pointer is the whole idempotency
 * story — while it is set the item already has a live shopping row, so we never create a second.
 *
 * Shopping -> Inventory: when that linked shopping row is marked bought, we top the stock back up (to its
 * target), log a "purchased" movement, and clear the pointer — so the item drops out of the low state and
 * becomes eligible to be added again the next time it runs low. One clean "low episode" per link.
 *
 * Automatic vs manual: `auto_replenish` ON means an item SHOULD surface for replenishment (it appears on the
 * Replenishment screen and is swept up by "Add all") and can be topped up with one tap. We deliberately do
 * NOT silently create a shopping row on every quantity edit — that would spam the list as stock wobbles
 * around the minimum. Creation happens on an explicit action (per-item add, or the bulk add), and the link
 * then blocks duplicates until the purchase closes the loop. An `auto_replenish` OFF item is never
 * auto-surfaced or swept by the bulk add; it can still be pushed manually from its own detail screen.
 */
class InventoryReplenishment
{
    /**
     * Surface one inventory item onto the family's default shopping list, idempotently. Returns the
     * shopping item (existing or freshly created), or null if the item can't currently be added.
     */
    public function addToShopping(InventoryItem $item, User $user): ?ShoppingItem
    {
        // Already linked to an open shopping row => no duplicate. Re-verify the row is really still there
        // and not bought (belt and suspenders: nullOnDelete and the bought listener normally keep the link
        // truthful, but a stale pointer must never block a legitimate re-add).
        if ($item->shopping_item_id !== null) {
            $existing = ShoppingItem::find($item->shopping_item_id);
            if ($existing && ! $existing->bought) {
                return $existing;
            }
            // The pointer is stale (row gone or already bought) — drop it and fall through to create anew.
            $item->forceFill(['shopping_item_id' => null])->save();
        }

        $list = ShoppingList::defaultFor($user);

        $shoppingItem = $list->items()->create([
            'name' => $item->name,
            'quantity' => $this->quantityLabel($item), // e.g. "to 500 g" — informs how much to buy
            'note' => 'From inventory',                // the Shopping module renders this verbatim (see note below)
            'created_by' => $user->id,
        ]);

        // Record the link on the inventory side. forceFill keeps this a pure link write (no quantity/state
        // change), so it never spuriously logs a stock movement.
        $item->forceFill(['shopping_item_id' => $shoppingItem->id])->save();

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return $shoppingItem;
    }

    /**
     * Bulk: surface every low/empty, auto-replenish, not-yet-linked item onto Shopping. Returns how many
     * rows were actually created (items already on the list are skipped, so calling twice is a no-op the
     * second time). This is what the "Add all to Shopping" buttons on Needs Attention and Replenishment call.
     */
    public function addAllLow(User $user): int
    {
        $added = 0;
        foreach ($this->replenishable() as $item) {
            if ($item->shopping_item_id !== null) {
                continue; // already open on a list — idempotent skip
            }
            if ($this->addToShopping($item, $user)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Close the loop: a linked shopping item was marked bought. Top the inventory item back up to its
     * target, log the purchase, and clear the link so it can be replenished again next time it runs low.
     * A no-op when the bought item isn't linked to any inventory item (an ordinary grocery line).
     */
    public function onShoppingItemBought(ShoppingItem $item): void
    {
        // The shopping item belongs to the same family as the inventory item, and this runs inside that
        // family's request, so the BelongsToFamily scope already narrows us correctly.
        $inventoryItem = InventoryItem::where('shopping_item_id', $item->id)->first();
        if (! $inventoryItem) {
            return;
        }

        $before = $inventoryItem->quantity;             // float|null ("unknown" counts as 0 for the delta)
        $after = $inventoryItem->replenishTarget();     // always a positive number — lifts it out of low/empty
        $delta = $after - ($before ?? 0.0);

        // Restock and drop the link in one write, so the item is immediately eligible to be re-added later.
        $inventoryItem->forceFill([
            'quantity' => $after,
            'shopping_item_id' => null,
        ])->save();

        // History: a "purchased" movement, carrying the signed change so it reads consistently with the
        // stored amount (the item detail + "Recently updated" feed pick it up like any other change).
        InventoryLog::create([
            'inventory_item_id' => $inventoryItem->id,
            'delta' => abs($delta) > 1e-9 ? $delta : null,
            'type' => 'purchased',
            'user_id' => $item->created_by,
        ]);
    }

    /**
     * The base query for replenishable stock: low/empty items whose auto-replenish is on. State is derived
     * in PHP (one source of truth), so we pre-filter to the cheap columns the states depend on and let
     * wantsReplenishment() make the final call — household inventories are small, so this is fine in memory.
     *
     * @return Collection<int, InventoryItem>
     */
    public function replenishable()
    {
        return InventoryItem::query()
            ->where('auto_replenish', true)
            ->whereNotNull('quantity')                  // "unknown" stock has no low/empty state to act on
            ->get()
            ->filter(fn (InventoryItem $i) => $i->wantsReplenishment());
    }

    /**
     * The shopping-line quantity hint. Prefer the explicit target ("to 500 g"); else the minimum
     * ("min 2 pcs"); else nothing — a plain named line. Built as a plain string on purpose: this is stored
     * on the shopping ITEM and rendered by the Shopping module verbatim (it never runs through the
     * inventory screens' translator), so a translation key here would surface as raw text. The unit is the
     * short token as stored (g/L/pcs…), which reads fine on a shopping list.
     */
    private function quantityLabel(InventoryItem $item): ?string
    {
        if ($item->target_quantity !== null && $item->target_quantity > 0) {
            return "to {$this->num($item->target_quantity)} {$item->unit}";
        }
        if ($item->min_quantity > 0) {
            return "min {$this->num($item->min_quantity)} {$item->unit}";
        }

        return null;
    }

    /** Trim a decimal for display (12.00 -> "12", 1.50 -> "1.5"), mirroring the client's fmtNumber. */
    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
