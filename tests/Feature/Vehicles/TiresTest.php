<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Tasks\Task;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleTimeline;
use Modules\Vehicles\VehicleTireSet;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks on — the modules the tyres feature spans. Distinct helper name so it doesn't
// clash with the other vehicles test files (Pest shares top-level functions across the suite).
function tyreFamily(): array
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
    foreach (['vehicles', 'tasks'] as $m) {
        $registry->enable($m, $family->id);
    }

    return [$family, $users];
}

function tyreVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// ---- CRUD ------------------------------------------------------------------------------------------

it('creates, updates and deletes a tyre set (family-scoped)', function () {
    [$family, $users] = tyreFamily();
    $vehicle = tyreVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", [
        'season' => 'winter', 'brand' => 'Michelin', 'size' => '225/55 R17',
        'tread_depth_mm' => 7.5, 'storage_location' => 'Garage shelf',
    ])->assertRedirect();

    $set = VehicleTireSet::first();
    expect($set)->not->toBeNull()
        ->and($set->vehicle_id)->toBe($vehicle->id)
        ->and($set->family_id)->toBe($family->id)
        ->and($set->season)->toBe('winter')
        ->and($set->brand)->toBe('Michelin')
        ->and($set->is_mounted)->toBeFalse()
        ->and($set->created_by)->toBe($users[0]->id);

    actingAs($users[0])->patch("/m/vehicles/{$vehicle->id}/tires/{$set->id}", [
        'season' => 'winter', 'brand' => 'Continental',
    ])->assertRedirect();
    expect($set->fresh()->brand)->toBe('Continental');

    actingAs($users[0])->delete("/m/vehicles/{$vehicle->id}/tires/{$set->id}")->assertRedirect();
    expect(VehicleTireSet::count())->toBe(0);
});

it('rejects an unknown season', function () {
    [, $users] = tyreFamily();
    $vehicle = tyreVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", ['season' => 'monsoon'])
        ->assertSessionHasErrors('season');
});

it('keeps tyre sets family-scoped', function () {
    [, $usersA] = tyreFamily();
    $vehicleA = tyreVehicle();
    actingAs($usersA[0])->post("/m/vehicles/{$vehicleA->id}/tires", ['season' => 'summer']);
    $setA = VehicleTireSet::first();

    [, $usersB] = tyreFamily();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/tires")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/tires", ['season' => 'summer'])->assertNotFound();
    actingAs($usersB[0])->patch("/m/vehicles/{$vehicleA->id}/tires/{$setA->id}", ['season' => 'summer'])->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/{$vehicleA->id}/tires/{$setA->id}")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/tires/{$setA->id}/mount")->assertNotFound();

    expect(VehicleTireSet::withoutGlobalScopes()->count())->toBe(1);
});

// ---- mount / one-mounted invariant -----------------------------------------------------------------

it('mounts a set, unmounting the previous one, and records the swap at current mileage', function () {
    [, $users] = tyreFamily();
    $vehicle = tyreVehicle(['current_mileage' => 148000]);

    // Winter set starts mounted (created with the flag -> swap runs on create).
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", ['season' => 'winter', 'is_mounted' => true]);
    // Summer set added (not mounted).
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", ['season' => 'summer']);

    $winter = VehicleTireSet::where('season', 'winter')->first();
    $summer = VehicleTireSet::where('season', 'summer')->first();
    expect($winter->is_mounted)->toBeTrue()->and($summer->is_mounted)->toBeFalse();

    // Swap to the summer set.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires/{$summer->id}/mount")->assertRedirect();

    expect($winter->fresh()->is_mounted)->toBeFalse()
        ->and($summer->fresh()->is_mounted)->toBeTrue()
        ->and($summer->fresh()->installed_mileage)->toBe(148000)      // stamped at the odometer
        ->and($summer->fresh()->installed_on?->toDateString())->toBe(now()->toDateString());

    // Exactly one set is ever mounted.
    expect(VehicleTireSet::where('vehicle_id', $vehicle->id)->where('is_mounted', true)->count())->toBe(1);
});

it('surfaces a tyre change on the vehicle timeline', function () {
    [, $users] = tyreFamily();
    $vehicle = tyreVehicle(['current_mileage' => 160000]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", ['season' => 'winter', 'brand' => 'Nokian', 'is_mounted' => true]);

    $entries = VehicleTimeline::forVehicle($vehicle->fresh());
    $tire = array_values(array_filter($entries, fn ($e) => $e['type'] === 'tire'));
    expect($tire)->toHaveCount(1)
        ->and($tire[0]['mileage'])->toBe(160000)
        ->and($tire[0]['title'])->toContain('Winter');
});

// ---- seasonal reminder (idempotent) ----------------------------------------------------------------

it('creates a seasonal tyre task idempotently and re-fires once the task is done', function () {
    [, $users] = tyreFamily();
    $vehicle = tyreVehicle();
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires", ['season' => 'winter']);
    $set = VehicleTireSet::first();

    // First reminder creates one task and links it.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires/{$set->id}/remind")->assertRedirect();
    $firstId = $set->fresh()->open_task_id;
    expect($firstId)->not->toBeNull()->and(Task::count())->toBe(1);

    // Reminding again while it's still open is a no-op — no duplicate.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires/{$set->id}/remind")->assertRedirect();
    expect(Task::count())->toBe(1)->and($set->fresh()->open_task_id)->toBe($firstId);

    // Once the task is checked off, the link self-heals and a fresh reminder may fire next season.
    Task::find($firstId)->update(['done' => true]);
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/tires/{$set->id}/remind")->assertRedirect();
    expect(Task::count())->toBe(2)->and($set->fresh()->open_task_id)->not->toBe($firstId);
});
