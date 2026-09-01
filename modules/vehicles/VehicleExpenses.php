<?php

namespace Modules\Vehicles;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendSetting;

/**
 * The Maintenance -> Spending bridge. Records a car expense as a real Spend in the existing Spending
 * module (never a parallel financial system) and links it to the vehicle via VehicleExpenseLink, so the
 * spend shows up in the family's spending exactly like any other and Spending can later total per-car.
 *
 * All car expenses land in one auto-provisioned per-family "Vehicles" spend event (a normal spending
 * list): it groups them together and, being family-visibility, every member sees the car's costs. The
 * per-car breakdown comes from the link table, not from this event, so the container is just a home.
 */
class VehicleExpenses
{
    /** The auto-provisioned per-family spend event that holds every car expense. */
    private const EVENT_NAME = 'Vehicles';

    /**
     * Category key for car expenses. `transport` is a built-in Spending category (Car icon) that every
     * family already has, so the spend renders cleanly in the analytics/category views — the true "which
     * car" link is the VehicleExpenseLink, not this string. Change here to re-categorise all future car
     * spends.
     */
    private const CATEGORY = 'transport';

    /**
     * Record an expense for a vehicle and link it. Returns the created Spend, or null when the family
     * doesn't have Spending enabled (the cost still lives on the maintenance history row regardless — we
     * simply don't mirror it into a module the family turned off).
     */
    public static function record(Vehicle $vehicle, User $actor, float $amount, string $note, string $spentAt, ?int $maintenanceHistoryId = null): ?Spend
    {
        $familyId = (int) $vehicle->family_id;
        if (! app(ModuleRegistry::class)->isEnabled('spending', $familyId)) {
            return null; // Spending is off for this family — nothing to mirror into.
        }
        if ($amount <= 0) {
            return null; // no cost, no expense
        }

        $event = self::eventFor($familyId, $actor);
        $currency = $event->currency;

        // buildSpend freezes the conversion to the event currency; here amount is already IN that currency
        // (we default it to the family's currency), so base_amount == amount and no FX surprise appears.
        $spend = $event->spends()->create([
            'payer_id' => $actor->id,
            ...$event->buildSpend([
                'amount' => $amount,
                'currency' => $currency,
                'spent_at' => $spentAt,
                'category' => self::CATEGORY,
                'note' => $note,
            ]),
            'created_by' => $actor->id,
        ]);

        VehicleExpenseLink::create([
            'vehicle_id' => $vehicle->id,
            'spend_id' => $spend->id,
            'maintenance_history_id' => $maintenanceHistoryId,
        ]);

        return $spend;
    }

    /**
     * Find-or-create the family's "Vehicles" spend event, provisioning its roster + owner share on first
     * creation exactly as the Spending controller does for a hand-made list. Currency defaults to the
     * family's spending default (falling back to EUR), so amounts entered without a currency picker are
     * interpreted sensibly.
     */
    private static function eventFor(int $familyId, User $actor): SpendEvent
    {
        $currency = SpendSetting::defaultCurrency($familyId) ?? 'EUR';

        $event = SpendEvent::firstOrCreate(
            ['name' => self::EVENT_NAME, 'family_id' => $familyId],
            ['currency' => $currency, 'icon' => 'car', 'created_by' => $actor->id],
        );

        if ($event->wasRecentlyCreated) {
            // Everyone in the family shares car costs; the actor owns the list (can manage it).
            $family = Family::find($familyId);
            if ($family) {
                $event->members()->sync($family->members->pluck('id'));
            }
            $event->shares()->firstOrCreate(['user_id' => $actor->id], ['role' => 'owner']);
        }

        return $event;
    }
}
