<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V1.1 Phase 3: the per-vehicle COST & CONSUMPTION analytics screen (the "Costs" tab). Everything here is
 * DERIVED from earlier phases (the vehicle->Spending links, the fuel log, the odometer history, the
 * maintenance schedules) — no new financial tracking. Visually it mirrors the Spending analytics screen
 * (period selector, total card, smooth trend curve, breakdown bars), reusing the same client components.
 *
 * Same tenancy shape as the other vehicle controllers: route-model binding resolves {vehicle} before the
 * `family` middleware sets the tenant, so the action guards the family explicitly.
 */
class VehicleAnalyticsController extends Controller
{
    /** The Costs tab: the selected period, the spend roll-up, the fuel metrics + trend, and the estimates. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        [$period, $start, $end] = VehicleAnalytics::range(
            $vehicle,
            $request->query('period'),
            $request->query('from'),
            $request->query('to'),
        );

        $analytics = VehicleAnalytics::forVehicle($vehicle, $start, $end);
        $fuelEntries = $vehicle->fuelEntries()->get();

        return Inertia::render('vehicles/analytics/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'unitSystem' => VehicleSetting::current()->unit_system,
            'period' => $period,
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            // The spend roll-up for the period (total, by-type breakdown, trend series, operating cost/km).
            'analytics' => $analytics,
            // Fuel metrics are consumption facts about the car, not period money — computed over the whole
            // fuel log so the average + trend read the same regardless of the selected spend period.
            'fuel' => FuelStats::forEntries($fuelEntries),
            'consumptionTrend' => FuelStats::consumptionTrend($fuelEntries),
            // Clearly-labelled projections (next service + next fill). Never presented as exact.
            'estimates' => VehicleAnalytics::estimates($vehicle),
            // The "Add expense"/Spending surfaces only mean something when Spending is on for the family.
            'canExpense' => app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()),
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
}
