<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleMileageLog;

use function Pest\Laravel\actingAs;

// A family with the vehicles module installed, enabled, and active in session + context (so vehicles
// seeded directly in the test get the right family_id and the global scope applies).
function vehiclesFamily(int $members = 1): array
{
    $users = User::factory()->count($members)->create();
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

// Seed a vehicle for the current family with sensible defaults, overridable per case.
function makeVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia',
        'model' => 'Duster',
        'year' => 2019,
        'version' => '1.5 dCi',
        'current_mileage' => 142340,
    ], $attrs));
}

it('404s the module pages when vehicles is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/vehicles')->assertNotFound();
    actingAs($user)->get('/m/vehicles/new')->assertNotFound();
});

it('reaches the module pages once enabled, and 404s again after disabling', function () {
    [$family, $users] = vehiclesFamily();

    actingAs($users[0])->get('/m/vehicles')->assertOk();

    app(ModuleRegistry::class)->disable('vehicles', $family->id);
    actingAs($users[0])->get('/m/vehicles')->assertNotFound();
});

it('renders My Cars with the family vehicles', function () {
    [, $users] = vehiclesFamily();
    makeVehicle(['make' => 'Dacia', 'model' => 'Duster']);
    makeVehicle(['make' => 'Opel', 'model' => 'Insignia', 'year' => 2012, 'current_mileage' => 218430]);

    actingAs($users[0])->get('/m/vehicles')->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/index')->has('vehicles', 2)
            ->where('vehicles.0.make', 'Dacia')   // ordered by make: Dacia before Opel
            ->where('vehicles.1.make', 'Opel')
            ->etc()
    );
});

it('creates a vehicle and opens its mileage history with the entered odometer', function () {
    [$family, $users] = vehiclesFamily();

    actingAs($users[0])->post('/m/vehicles', [
        'make' => 'Dacia',
        'model' => 'Duster',
        'year' => 2019,
        'version' => '1.5 dCi',
        'fuel_type' => 'diesel',
        'transmission' => 'manual',
        'current_mileage' => 142340,
    ])->assertRedirect();

    $vehicle = Vehicle::first();
    expect($vehicle)->not->toBeNull();
    expect($vehicle->make)->toBe('Dacia');
    expect($vehicle->current_mileage)->toBe(142340);
    expect($vehicle->created_by)->toBe($users[0]->id);
    expect($vehicle->family_id)->toBe($family->id);

    // The opening odometer reading is logged, so history and current_mileage agree from day one.
    $log = VehicleMileageLog::where('vehicle_id', $vehicle->id)->first();
    expect($log)->not->toBeNull();
    expect($log->mileage)->toBe(142340);
    expect($log->recorded_by)->toBe($users[0]->id);
});

it('rejects an invalid fuel type', function () {
    [, $users] = vehiclesFamily();

    actingAs($users[0])->post('/m/vehicles', [
        'make' => 'Dacia',
        'model' => 'Duster',
        'fuel_type' => 'plutonium',
    ])->assertSessionHasErrors('fuel_type');
});

it('edits a vehicle without touching the odometer', function () {
    [, $users] = vehiclesFamily();
    $vehicle = makeVehicle(['current_mileage' => 142340]);

    actingAs($users[0])->patch("/m/vehicles/{$vehicle->id}", [
        'make' => 'Dacia',
        'model' => 'Duster',
        'color' => 'Blue',
        'current_mileage' => 999999,   // must be ignored — odometer only moves via the mileage endpoint
    ])->assertRedirect();

    $vehicle->refresh();
    expect($vehicle->color)->toBe('Blue');
    expect($vehicle->current_mileage)->toBe(142340);   // unchanged by the edit
});

it('keeps vehicles family-scoped and does not leak across families', function () {
    [, $usersA] = vehiclesFamily();
    $vehicleA = makeVehicle(['make' => 'Dacia']);

    // A second family the user A does not belong to.
    [, $usersB] = vehiclesFamily();

    // User B cannot see or reach family A's vehicle.
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}")->assertNotFound();
    actingAs($usersB[0])->patch("/m/vehicles/{$vehicleA->id}", ['make' => 'Hacked', 'model' => 'X'])->assertNotFound();

    $vehicleA->refresh();
    expect($vehicleA->make)->toBe('Dacia');
});

it('records a mileage update: logs the reading and moves current_mileage', function () {
    [, $users] = vehiclesFamily();
    $vehicle = makeVehicle(['current_mileage' => 142340]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/mileage", ['mileage' => 142440])->assertRedirect();

    $vehicle->refresh();
    expect($vehicle->current_mileage)->toBe(142440);
    expect(VehicleMileageLog::where('vehicle_id', $vehicle->id)->where('mileage', 142440)->exists())->toBeTrue();
});

it('orders mileage history newest first on the overview', function () {
    [, $users] = vehiclesFamily();
    $vehicle = makeVehicle(['current_mileage' => 142340]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/mileage", ['mileage' => 142500]);
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/mileage", ['mileage' => 142800]);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->where('vehicle.currentMileage', 142800)
            ->where('history.0.mileage', 142800)   // newest first
            ->where('history.1.mileage', 142500)
            ->etc()
    );
});

it('blocks a backwards mileage reading unless it is an explicit correction', function () {
    [, $users] = vehiclesFamily();
    $vehicle = makeVehicle(['current_mileage' => 142340]);

    // A lower reading without the correction flag is rejected and the odometer is untouched.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/mileage", ['mileage' => 100000])
        ->assertSessionHasErrors('mileage');
    expect($vehicle->fresh()->current_mileage)->toBe(142340);

    // The same reading flagged as a correction is authoritative: it becomes the new odometer.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/mileage", ['mileage' => 100000, 'correction' => true])
        ->assertRedirect();
    expect($vehicle->fresh()->current_mileage)->toBe(100000);
});

it('deletes a vehicle and cascades its mileage history', function () {
    [, $users] = vehiclesFamily();
    $vehicle = makeVehicle();
    $vehicleId = $vehicle->id;

    actingAs($users[0])->delete("/m/vehicles/{$vehicleId}")->assertRedirect('/m/vehicles');

    expect(Vehicle::find($vehicleId))->toBeNull();
    expect(VehicleMileageLog::where('vehicle_id', $vehicleId)->exists())->toBeFalse();
});
