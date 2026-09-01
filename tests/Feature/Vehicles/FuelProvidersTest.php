<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\Spend;
use Modules\Vehicles\FuelStats;
use Modules\Vehicles\Repair;
use Modules\Vehicles\ServiceProvider;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExpenseLink;
use Modules\Vehicles\VehicleFuelEntry;
use Modules\Vehicles\VehicleMaintenanceHistory;
use Modules\Vehicles\VehicleTimeline;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending on — the modules V1.1 P1 spans. Distinct helper name so it
// doesn't clash with the other vehicles test files (Pest shares top-level functions across the suite).
function v11Family(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    $registry = app(ModuleRegistry::class);
    foreach (['vehicles', 'tasks', 'spending'] as $m) {
        $registry->enable($m, $family->id);
    }

    return [$family, $users];
}

function v11Vehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// ---- Fuel ------------------------------------------------------------------------------------------

it('records a fuel entry and moves the odometer once, without double-counting', function () {
    [$family, $users] = v11Family();
    $vehicle = v11Vehicle(['current_mileage' => 150000]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/fuel", [
        'filled_on' => '2026-08-20',
        'liters' => 52,
        'cost' => 90,
        'mileage' => 150500,
        'is_full_tank' => true,
        'station' => 'OMV',
    ])->assertRedirect();

    $entry = VehicleFuelEntry::first();
    expect($entry)->not->toBeNull()
        ->and($entry->vehicle_id)->toBe($vehicle->id)
        ->and($entry->family_id)->toBe($family->id)
        ->and((float) $entry->liters)->toBe(52.0)
        ->and($entry->mileage)->toBe(150500)
        ->and($entry->is_full_tank)->toBeTrue()
        ->and($entry->created_by)->toBe($users[0]->id);

    // The fill advanced the odometer...
    expect($vehicle->fresh()->current_mileage)->toBe(150500);
    // ...and wrote EXACTLY ONE mileage-log row for it (no double count).
    expect($vehicle->mileageLogs()->count())->toBe(1);
});

it('does not rewind the odometer (or log) for a back-dated, lower-mileage fill', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle(['current_mileage' => 150000]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/fuel", [
        'filled_on' => '2026-07-01', 'liters' => 40, 'mileage' => 149000, 'is_full_tank' => true,
    ])->assertRedirect();

    expect($vehicle->fresh()->current_mileage)->toBe(150000)
        ->and($vehicle->mileageLogs()->count())->toBe(0);
});

it('computes L/100km between two full-tank fills over a known mileage delta', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle(['current_mileage' => 100000]);

    // Full tank at 100,000 km (baseline), then a full tank of 40 L at 100,500 km.
    $vehicle->fuelEntries()->create(['filled_on' => '2026-01-01', 'liters' => 50, 'mileage' => 100000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => '2026-02-01', 'liters' => 40, 'mileage' => 100500, 'is_full_tank' => true, 'created_by' => $users[0]->id]);

    $stats = FuelStats::forEntries($vehicle->fuelEntries()->get());

    // 40 L over 500 km = 8.0 L/100km.
    expect($stats['latestConsumption'])->toBe(8.0)
        ->and($stats['avgConsumption'])->toBe(8.0)
        ->and($stats['distanceTracked'])->toBe(500);
});

it('counts a partial fill toward the next full-tank segment', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle(['current_mileage' => 100000]);

    // Full @0km, partial 20L @250km, full 30L @500km -> 50L burned over 500km = 10.0 L/100km.
    $vehicle->fuelEntries()->create(['filled_on' => '2026-01-01', 'liters' => 45, 'mileage' => 100000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => '2026-01-10', 'liters' => 20, 'mileage' => 100250, 'is_full_tank' => false, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => '2026-01-20', 'liters' => 30, 'mileage' => 100500, 'is_full_tank' => true, 'created_by' => $users[0]->id]);

    $stats = FuelStats::forEntries($vehicle->fuelEntries()->get());
    expect($stats['latestConsumption'])->toBe(10.0);
});

it('mirrors a costed fill into Spending linked to the car and the fuel entry when opted in', function () {
    [$family, $users] = v11Family();
    $vehicle = v11Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/fuel", [
        'filled_on' => '2026-08-20', 'liters' => 50, 'cost' => 90.50, 'mileage' => 151000,
        'is_full_tank' => true, 'create_expense' => true,
    ])->assertRedirect();

    $spend = Spend::first();
    expect($spend)->not->toBeNull()
        ->and($spend->amount)->toBe(9050)               // minor units
        ->and($spend->family_id)->toBe($family->id);

    $entry = VehicleFuelEntry::first();
    $link = VehicleExpenseLink::where('spend_id', $spend->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->fuel_entry_id)->toBe($entry->id)
        ->and($link->maintenance_history_id)->toBeNull()
        ->and($link->repair_id)->toBeNull();

    // A fuel cost is shown once, as the fill — not as a separate ad-hoc "expense" entry on the timeline.
    $expenseEntries = array_filter(VehicleTimeline::forVehicle($vehicle->fresh()), fn ($e) => $e['type'] === 'expense');
    expect($expenseEntries)->toBe([]);
});

it('does not create a spend for a costed fill without the opt-in', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/fuel", [
        'filled_on' => '2026-08-20', 'liters' => 50, 'cost' => 90, 'mileage' => 151000,
    ])->assertRedirect();

    expect(Spend::count())->toBe(0)->and(VehicleExpenseLink::count())->toBe(0);
});

it('keeps fuel entries family-scoped', function () {
    [, $usersA] = v11Family();
    $vehicleA = v11Vehicle();
    actingAs($usersA[0])->post("/m/vehicles/{$vehicleA->id}/fuel", ['filled_on' => '2026-08-20', 'liters' => 40, 'mileage' => 151000]);
    $entryA = VehicleFuelEntry::first();

    [, $usersB] = v11Family();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/fuel")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/fuel", ['filled_on' => '2026-08-20', 'liters' => 1, 'mileage' => 1])->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/{$vehicleA->id}/fuel/{$entryA->id}")->assertNotFound();

    expect(VehicleFuelEntry::withoutGlobalScopes()->count())->toBe(1);
});

// ---- Service providers -----------------------------------------------------------------------------

it('creates, updates and deletes a service provider (family-scoped)', function () {
    [$family, $users] = v11Family();

    actingAs($users[0])->post('/m/vehicles/providers', [
        'name' => 'AutoService SRL', 'phone' => '0722 000 000', 'address' => 'Str. Garajului 1',
    ])->assertRedirect();

    $provider = ServiceProvider::first();
    expect($provider)->not->toBeNull()
        ->and($provider->name)->toBe('AutoService SRL')
        ->and($provider->family_id)->toBe($family->id)
        ->and($provider->created_by)->toBe($users[0]->id);

    actingAs($users[0])->patch("/m/vehicles/providers/{$provider->id}", ['name' => 'AutoService Plus'])->assertRedirect();
    expect($provider->fresh()->name)->toBe('AutoService Plus');

    actingAs($users[0])->delete("/m/vehicles/providers/{$provider->id}")->assertRedirect();
    expect(ServiceProvider::count())->toBe(0);
});

it('keeps service providers family-scoped', function () {
    [, $usersA] = v11Family();
    actingAs($usersA[0])->post('/m/vehicles/providers', ['name' => 'A Garage']);
    $providerA = ServiceProvider::first();

    [, $usersB] = v11Family();
    actingAs($usersB[0])->get("/m/vehicles/providers/{$providerA->id}")->assertNotFound();
    actingAs($usersB[0])->patch("/m/vehicles/providers/{$providerA->id}", ['name' => 'hacked'])->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/providers/{$providerA->id}")->assertNotFound();

    expect(ServiceProvider::withoutGlobalScopes()->find($providerA->id)->name)->toBe('A Garage');
});

it('links a service provider to a repair and lists it in the provider history', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle();
    actingAs($users[0])->post('/m/vehicles/providers', ['name' => 'AutoService SRL']);
    $provider = ServiceProvider::first();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'New clutch', 'occurred_on' => '2026-08-20', 'cost' => 540,
        'service_provider_id' => $provider->id,
    ])->assertRedirect();

    $repair = Repair::first();
    expect($repair->service_provider_id)->toBe($provider->id)
        ->and($repair->providerName())->toBe('AutoService SRL');   // resolves the linked provider's name

    // The provider detail lists the repair in its service history.
    actingAs($users[0])->get("/m/vehicles/providers/{$provider->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/providers/show')->has('history', 1)
            ->where('history.0.title', 'New clutch')->etc()
    );
});

it('links a service provider to a maintenance completion', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle();
    $schedule = $vehicle->maintenanceSchedules()->create([
        'name' => 'Oil change', 'type' => 'oil_change', 'interval_km' => 15000, 'created_by' => $users[0]->id,
    ]);
    actingAs($users[0])->post('/m/vehicles/providers', ['name' => 'Quick Lube']);
    $provider = ServiceProvider::first();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-08-20', 'mileage' => 151000, 'cost' => 80,
        'service_provider_id' => $provider->id,
    ])->assertRedirect();

    $history = VehicleMaintenanceHistory::first();
    expect($history->service_provider_id)->toBe($provider->id)
        ->and($history->providerName())->toBe('Quick Lube');
});

it('rejects a service provider from another family on a repair', function () {
    [, $usersA] = v11Family();
    actingAs($usersA[0])->post('/m/vehicles/providers', ['name' => 'Foreign Garage']);
    $foreign = ServiceProvider::first();

    [, $usersB] = v11Family();
    $vehicleB = v11Vehicle();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleB->id}/repairs", [
        'title' => 'X', 'occurred_on' => '2026-08-20', 'service_provider_id' => $foreign->id,
    ])->assertSessionHasErrors('service_provider_id');
});

it('still renders a free-text garage on a repair with no linked provider (backward-compat)', function () {
    [, $users] = v11Family();
    $vehicle = v11Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'Brake job', 'occurred_on' => '2026-08-20', 'provider' => 'Corner Garage',
    ])->assertRedirect();

    $repair = Repair::first();
    expect($repair->service_provider_id)->toBeNull()
        ->and($repair->providerName())->toBe('Corner Garage');   // free text still displays

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/repairs")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/repairs/index')->where('repairs.0.provider', 'Corner Garage')->etc()
    );
});
