<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleMaintenanceHistory;
use Modules\Vehicles\VehicleMaintenanceSchedule;
use Modules\Vehicles\VehicleMileageLog;

use function Pest\Laravel\actingAs;

// A family with the vehicles module enabled + active (so seeded rows get the right family_id and the
// global scope applies). Named distinctly from VehiclesTest's helper to avoid a cross-file clash.
function maintFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('vehicles', $family->id);

    return [$family, $users];
}

function maintVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 142340,
    ], $attrs));
}

function makeSchedule(Vehicle $vehicle, array $attrs = []): VehicleMaintenanceSchedule
{
    return $vehicle->maintenanceSchedules()->create(array_merge([
        'name' => 'Oil & filter', 'type' => 'oil_change',
        'interval_km' => 15000, 'interval_months' => 12,
        'last_performed_mileage' => 134200, 'last_performed_at' => '2026-03-12',
    ], $attrs));
}

it('creates a maintenance schedule and opens its detail', function () {
    [$family, $users] = maintFamily();
    $vehicle = maintVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance", [
        'name' => 'Oil & filter',
        'type' => 'oil_change',
        'interval_km' => 15000,
        'interval_months' => 12,
        'last_performed_mileage' => 134200,
        'last_performed_at' => '2026-03-12',
    ])->assertRedirect();

    $schedule = VehicleMaintenanceSchedule::first();
    expect($schedule)->not->toBeNull()
        ->and($schedule->vehicle_id)->toBe($vehicle->id)
        ->and($schedule->family_id)->toBe($family->id)
        ->and($schedule->interval_km)->toBe(15000)
        ->and($schedule->created_by)->toBe($users[0]->id);
});

it('requires at least one interval', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance", [
        'name' => 'Mystery', 'type' => 'custom',
    ])->assertSessionHasErrors(['interval_km', 'interval_months']);
});

it('lists schedules most-urgent first with their computed status', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 150000]);

    // Good: next due far off. Overdue: long past both. Due-soon: 700 km short of due.
    makeSchedule($vehicle, ['name' => 'Coolant', 'interval_km' => 60000, 'interval_months' => null, 'last_performed_mileage' => 140000, 'last_performed_at' => null]);
    makeSchedule($vehicle, ['name' => 'Timing belt', 'interval_km' => 90000, 'interval_months' => 60, 'last_performed_mileage' => 50000, 'last_performed_at' => '2018-01-01']);
    makeSchedule($vehicle, ['name' => 'Brake pads', 'interval_km' => 40000, 'interval_months' => null, 'last_performed_mileage' => 110700, 'last_performed_at' => null]);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/maintenance")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/maintenance/index')
            ->has('schedules', 3)
            ->where('schedules.0.name', 'Timing belt')   // overdue floats to the top
            ->where('schedules.0.status', 'overdue')
            ->where('schedules.2.status', 'good')          // coolant, far off, sinks to the bottom
            ->etc()
    );
});

it('shows the event detail with computed status and history', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 142340]);
    $schedule = makeSchedule($vehicle);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/maintenance/detail')
            ->where('schedule.nextDueMileage', 149200)     // 134200 + 15000
            ->where('schedule.remainingKm', 6860)          // 149200 - 142340
            ->has('history', 0)
            ->etc()
    );
});

it('marks a schedule completed: writes history and advances the baseline', function () {
    [$family, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 150000]);
    // Overdue before completion (next was due at 149,200 km, now at 150,000 km).
    $schedule = makeSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-09-01',
        'mileage' => 150000,
        'cost' => 380.50,
        'provider' => 'AutoService SRL',
        'notes' => 'Oil + filter changed',
    ])->assertRedirect();

    $schedule->refresh();
    // Baseline advanced to the performance — next due recomputes to 165,000 km / 2027-09-01.
    expect($schedule->last_performed_mileage)->toBe(150000)
        ->and($schedule->last_performed_at->toDateString())->toBe('2026-09-01');

    $status = $schedule->statusFor(150000, Carbon::parse('2026-09-01'));
    expect($status['status'])->toBe('good')
        ->and($status['nextDueMileage'])->toBe(165000)
        ->and($status['remainingKm'])->toBe(15000);

    $history = VehicleMaintenanceHistory::where('schedule_id', $schedule->id)->first();
    expect($history)->not->toBeNull()
        ->and($history->mileage)->toBe(150000)
        ->and((float) $history->cost)->toBe(380.50)
        ->and($history->provider)->toBe('AutoService SRL')
        ->and($history->name)->toBe('Oil & filter')      // snapshot of the schedule name
        ->and($history->family_id)->toBe($family->id);
});

it('advances the odometer (and logs it) when completed at a higher mileage', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 150000]);
    $schedule = makeSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-09-01', 'mileage' => 152000,
    ])->assertRedirect();

    expect($vehicle->fresh()->current_mileage)->toBe(152000)
        ->and(VehicleMileageLog::where('vehicle_id', $vehicle->id)->where('mileage', 152000)->exists())->toBeTrue();
});

it('does not rewind the odometer for a back-dated completion at a lower mileage', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 150000]);
    $schedule = makeSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2025-01-01', 'mileage' => 130000,
    ])->assertRedirect();

    // The odometer is untouched, but the history record keeps the historical mileage.
    expect($vehicle->fresh()->current_mileage)->toBe(150000)
        ->and(VehicleMileageLog::where('vehicle_id', $vehicle->id)->where('mileage', 130000)->exists())->toBeFalse()
        ->and(VehicleMaintenanceHistory::where('schedule_id', $schedule->id)->value('mileage'))->toBe(130000);
});

it('updates a schedule and can archive it (active toggle)', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle();
    $schedule = makeSchedule($vehicle);

    actingAs($users[0])->patch("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}", [
        'name' => 'Oil change', 'type' => 'oil_change',
        'interval_km' => 10000, 'interval_months' => 12, 'active' => false,
    ])->assertRedirect();

    $schedule->refresh();
    expect($schedule->interval_km)->toBe(10000)->and($schedule->active)->toBeFalse();
});

it('excludes inactive schedules from the vehicle status rollup', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 200000]);
    // A wildly overdue schedule that is archived — it must not colour the card red.
    makeSchedule($vehicle, ['active' => false, 'last_performed_mileage' => 100000]);

    $summary = $vehicle->fresh()->load('maintenanceSchedules')->maintenanceSummary();
    expect($summary['state'])->toBe('good')->and($summary['attention'])->toBe(0);
});

it('deletes a schedule but keeps its history record (unlinked)', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 150000]);
    $schedule = makeSchedule($vehicle);
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-09-01', 'mileage' => 150000,
    ]);
    $historyId = VehicleMaintenanceHistory::where('schedule_id', $schedule->id)->value('id');

    actingAs($users[0])->delete("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}")
        ->assertRedirect("/m/vehicles/{$vehicle->id}/maintenance");

    expect(VehicleMaintenanceSchedule::find($schedule->id))->toBeNull();
    $history = VehicleMaintenanceHistory::find($historyId);
    expect($history)->not->toBeNull()->and($history->schedule_id)->toBeNull();   // record survives, unlinked
});

it('keeps schedules family-scoped and guards the vehicle/schedule pair', function () {
    [, $usersA] = maintFamily();
    $vehicleA = maintVehicle();
    $scheduleA = makeSchedule($vehicleA);

    // A second family + its own vehicle.
    [, $usersB] = maintFamily();
    $vehicleB = maintVehicle(['make' => 'Opel']);

    // User B cannot reach family A's schedule at all.
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/maintenance/{$scheduleA->id}")->assertNotFound();

    // Even within a family, a schedule that doesn't belong to the addressed vehicle 404s (no cross-wiring).
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleB->id}/maintenance/{$scheduleA->id}")->assertNotFound();
});

it('surfaces the worst schedule status on the My Cars card', function () {
    [, $users] = maintFamily();
    $vehicle = maintVehicle(['current_mileage' => 160000]);
    makeSchedule($vehicle, ['name' => 'Good one', 'last_performed_mileage' => 158000, 'interval_km' => 15000, 'interval_months' => null, 'last_performed_at' => null]);
    makeSchedule($vehicle, ['name' => 'Overdue one', 'last_performed_mileage' => 130000, 'interval_km' => 15000, 'interval_months' => null, 'last_performed_at' => null]);

    actingAs($users[0])->get('/m/vehicles')->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/index')
            ->where('vehicles.0.state', 'overdue')       // worst of the two wins
            ->where('vehicles.0.attention', 1)
            ->etc()
    );
});
