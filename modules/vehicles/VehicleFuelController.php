<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V1.1 Phase 1: FUEL — a lightweight per-vehicle fill-up log (NOT a full fuel-management system). The tab
 * lists fills and shows computed metrics (consumption L/100km between full-tank fills, cost per km, monthly
 * + annual fuel cost — all from FuelStats), and Add fuel records a fill.
 *
 * A fill reuses the module's mileage flow: recording it at a higher odometer moves current_mileage forward
 * and writes ONE mileage_log row (the exact guard MaintenanceCompletion uses), so it never double-counts the
 * odometer. A costed fill mirrors into Spending, linked to the car AND tagged with the fuel entry, via the
 * P3 VehicleExpenseLink bridge.
 *
 * Same tenancy shape as the other vehicle controllers: route-model binding resolves {vehicle}/{entry} before
 * the `family` middleware sets the tenant, so every action guards the family explicitly.
 */
class VehicleFuelController extends Controller
{
    /** Fuel tab: the fill history (newest first), the computed metrics, and whether Spending is on. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        $entries = $vehicle->fuelEntries()->get();

        return Inertia::render('vehicles/fuel/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'unitSystem' => VehicleSetting::current()->unit_system,
            'entries' => $entries->map(fn (VehicleFuelEntry $e) => $e->toPayload())->values(),
            // Metrics are computed from every entry regardless of the display order above.
            'stats' => FuelStats::forEntries($entries),
            // Offer "also add to Spending" only when the family actually has Spending on.
            'canExpense' => app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()),
        ]);
    }

    /** Record a fill-up: move the odometer forward if it advanced, and mirror a cost into Spending on opt-in. */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateEntry($request);

        // Entry + forward-only odometer bump + opt-in Spending record (store-time only, so an edit never
        // duplicates it). Shared with the MCP tool: see Vehicle::logFuel.
        $vehicle->logFuel($data, $request->user());

        return back();
    }

    /** Edit a fill's details. No expense/odometer side-effects — those are store-time only (avoids dupes). */
    public function update(Request $request, Vehicle $vehicle, VehicleFuelEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($vehicle, $entry);
        $data = $this->validateEntry($request);

        $entry->update([
            'filled_on' => $data['filled_on'],
            'liters' => $data['liters'],
            'cost' => $data['cost'] ?? null,
            'mileage' => $data['mileage'],
            'is_full_tank' => $data['is_full_tank'] ?? true,
            'station' => $data['station'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return back();
    }

    /**
     * Delete a fill. Any Spend it mirrored stays (the money was still spent); the expense link's
     * fuel_entry_id nulls out (FK nullOnDelete), so that cost simply reverts to an ad-hoc car expense.
     */
    public function destroy(Request $request, Vehicle $vehicle, VehicleFuelEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($vehicle, $entry);
        $entry->delete();

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Shared validation for add/edit. is_full_tank defaults true (the common case, and what makes
     * consumption computable). create_expense is add-only (the frontend hides it on edit).
     *
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'filled_on' => ['required', 'date'],
            'liters' => ['required', 'numeric', 'gt:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'mileage' => ['required', 'integer', 'min:0'],
            'is_full_tank' => ['sometimes', 'boolean'],
            'station' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'create_expense' => ['sometimes', 'boolean'],
        ]);
    }

    /** The compact vehicle header shape every per-car screen needs (title + odometer + state dot). */
    private function vehiclePayload(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'version' => $vehicle->version,
            'currentMileage' => (int) $vehicle->current_mileage,
            ...$vehicle->maintenanceSummary(),
        ];
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);
    }

    /** Guard the vehicle's family AND that the entry belongs to this vehicle. */
    private function authorizeEntry(Vehicle $vehicle, VehicleFuelEntry $entry): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $entry->vehicle_id === (int) $vehicle->id, 404);
    }
}
