<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * V1.1 Phase 2: parts -> Shopping. Adds a part to the family's shopping list (labelled with the car) and
 * removes a request. All the actual mirror/idempotency logic lives in VehicleParts so the hook listener and
 * these endpoints agree. Same explicit family/vehicle guards as the other vehicle controllers.
 */
class VehiclePartsController extends Controller
{
    public function __construct(private VehicleParts $parts) {}

    /**
     * Add a part to Shopping. Idempotent (VehicleParts dedups a still-open request). An optional origin —
     * a maintenance schedule or a repair belonging to THIS car — records what the part is for.
     */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);

        // Adding a part needs somewhere to add it — require Shopping to be on for the family.
        abort_unless(app(ModuleRegistry::class)->isEnabled('shopping', (int) CurrentFamily::id()), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'maintenance_schedule_id' => ['nullable', 'integer'],
            'repair_id' => ['nullable', 'integer'],
        ]);

        // Guard any origin id to this vehicle, so a request can't reference another car's service.
        $scheduleId = $this->originId($vehicle, 'maintenanceSchedules', $data['maintenance_schedule_id'] ?? null);
        $repairId = $this->originId($vehicle, 'repairs', $data['repair_id'] ?? null);

        $this->parts->addToShopping(
            $vehicle,
            $request->user(),
            $data['name'],
            isset($data['estimated_cost']) ? (float) $data['estimated_cost'] : null,
            $scheduleId,
            $repairId,
        );

        return back();
    }

    /** Remove a part request. Any shopping row it created is left alone (the family may still want to buy it). */
    public function destroy(Request $request, Vehicle $vehicle, VehiclePartRequest $part): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $part->vehicle_id === (int) $vehicle->id, 404);

        $part->delete();

        return back();
    }

    /**
     * Validate that an origin id (schedule / repair) belongs to this vehicle; return it when it does, else
     * null (a bad or foreign id is silently dropped to a general vehicle-level part rather than 404ing).
     */
    private function originId(Vehicle $vehicle, string $relation, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return $vehicle->{$relation}()->whereKey($id)->exists() ? $id : null;
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);
    }
}
