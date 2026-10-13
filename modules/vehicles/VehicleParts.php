<?php

namespace Modules\Vehicles;

use App\Events\ShoppingChanged;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

/**
 * The Vehicles <-> Shopping "buy parts" loop, in one place so the controller and the shopping.item.bought
 * hook listener share exactly the same rules — mirroring InventoryReplenishment.
 *
 * Vehicle -> Shopping: a requested part becomes a real shopping row on the family's default list, labelled
 * with the car ("Bosch brake pads — Dacia Duster"). The part request keeps a pointer to that open row
 * (shopping_item_id); that pointer is the whole idempotency story — while it is set (and the row is still
 * open) we never create a second row for the same requested part.
 *
 * Shopping -> Vehicle: when that linked shopping row is marked bought, the part flips to `bought`, and — if
 * it carried an estimated cost and Spending is on — the purchase mirrors into Spending, linked to the car
 * and the part via the P3 VehicleExpenseLink bridge. A shopping item has no price field, so an explicit
 * estimate is deliberately how the cost reaches Spending; a part with no estimate is simply recorded as
 * bought against the vehicle (its purchase is captured, no fabricated amount).
 */
class VehicleParts
{
    /**
     * Add (or reuse) a part on the family's default shopping list, idempotently. Returns the part request.
     * Re-requesting the same still-open part for the same car returns the existing request without creating
     * a second shopping row.
     */
    public function addToShopping(
        Vehicle $vehicle,
        User $user,
        string $name,
        ?float $estimatedCost = null,
        ?int $maintenanceScheduleId = null,
        ?int $repairId = null,
    ): VehiclePartRequest {
        $name = trim($name);

        // Idempotency: an existing REQUESTED part with the same (case-insensitive) name for this car. If it
        // still points at a live, unbought shopping row, reuse it as-is. If its pointer is stale (row gone or
        // already bought), fall through and give it a fresh shopping row.
        $existing = $vehicle->partRequests()
            ->where('status', VehiclePartRequest::STATUS_REQUESTED)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get()
            ->first();

        if ($existing && $existing->shopping_item_id !== null) {
            $row = ShoppingItem::find($existing->shopping_item_id);
            if ($row && ! $row->bought) {
                return $existing; // already open on a list — no duplicate
            }
        }

        $part = $existing ?? $vehicle->partRequests()->create([
            'name' => $name,
            'estimated_cost' => $estimatedCost,
            'status' => VehiclePartRequest::STATUS_REQUESTED,
            'maintenance_schedule_id' => $maintenanceScheduleId,
            'repair_id' => $repairId,
            'created_by' => $user->id,
        ]);

        // Mirror onto Shopping, labelled with the car. The label is what makes the row self-explanatory on a
        // shared family list.
        $list = ShoppingList::defaultFor($user);
        $shoppingItem = $list->items()->create([
            'name' => $this->label($vehicle, $name),
            'note' => 'Vehicle part',
            'created_by' => $user->id,
        ]);

        // Record the link (and refresh the estimate if the re-add changed it). forceFill keeps this a pure
        // link write on an existing row.
        $part->forceFill([
            'shopping_item_id' => $shoppingItem->id,
            'estimated_cost' => $estimatedCost ?? $part->estimated_cost,
        ])->save();

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return $part;
    }

    /**
     * Close the loop: a linked shopping row was marked bought. Flip the part to `bought`, drop the link, and
     * — when it carried an estimated cost and Spending is on — mirror the purchase into Spending linked to
     * the car. A no-op when the bought item isn't a vehicle part (an ordinary shopping line).
     */
    public function onShoppingItemBought(ShoppingItem $item): void
    {
        // The shopping item shares the family, and this runs inside that family's request, so BelongsToFamily
        // already narrows the lookup correctly. whereHas: a trashed car's part request stays live (hidden with
        // the car) and must not mirror a cost into Spending meanwhile.
        $part = VehiclePartRequest::where('shopping_item_id', $item->id)->whereHas('vehicle')->first();
        if (! $part) {
            return;
        }

        $vehicle = $part->vehicle;

        $part->forceFill([
            'status' => VehiclePartRequest::STATUS_BOUGHT,
            'bought_on' => now()->toDateString(),
            'shopping_item_id' => null, // loop closed — the request can be re-made later if needed
        ])->save();

        // Mirror the cost into Spending only when we actually know it (estimate set) and the car still exists.
        // VehicleExpenses::record itself no-ops when Spending is off or the amount is not positive, so this is
        // safe to call unconditionally past the estimate check.
        if ($vehicle && $part->estimated_cost !== null && $part->estimated_cost > 0) {
            $actor = User::find($item->created_by) ?? User::find($part->created_by);
            if ($actor) {
                VehicleExpenses::record(
                    $vehicle,
                    $actor,
                    (float) $part->estimated_cost,
                    $this->label($vehicle, $part->name),
                    now()->toDateString(),
                    null,          // not a maintenance completion
                    null,          // not a repair
                    null,          // not a fuel fill
                    $part->id,     // tag the part request
                );
            }
        }
    }

    /** The car-labelled part name, e.g. "Bosch brake pads — Dacia Duster". */
    private function label(Vehicle $vehicle, string $name): string
    {
        $car = trim("{$vehicle->make} {$vehicle->model}");

        return trim($name).($car !== '' ? " — {$car}" : '');
    }
}
