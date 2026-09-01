<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;
use Modules\Spending\Spend;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExpenseLink;
use Modules\Vehicles\VehiclePartRequest;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending + Shopping on — the modules the "buy parts" feature spans.
// Distinct helper name so it doesn't clash with the other vehicles test files (Pest shares top-level fns).
function partsFamily(): array
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
    foreach (['vehicles', 'tasks', 'spending', 'shopping'] as $m) {
        $registry->enable($m, $family->id);
    }

    return [$family, $users];
}

function partsVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

it('adds a part to shopping labelled with the vehicle, idempotently', function () {
    [$family, $users] = partsFamily();
    $vehicle = partsVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/parts", ['name' => 'Bosch brake pads'])->assertRedirect();
    // Re-adding the same still-open part must not create a second request or a second shopping row.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/parts", ['name' => 'bosch brake pads'])->assertRedirect();

    expect(VehiclePartRequest::count())->toBe(1)->and(ShoppingItem::count())->toBe(1);

    $part = VehiclePartRequest::first();
    $item = ShoppingItem::first();
    expect($part->name)->toBe('Bosch brake pads')
        ->and($part->status)->toBe('requested')
        ->and($part->family_id)->toBe($family->id)
        ->and($part->shopping_item_id)->toBe($item->id)
        ->and($item->name)->toBe('Bosch brake pads — Dacia Duster');
});

it('mirrors a bought part with an estimated cost into the car spending', function () {
    [$family, $users] = partsFamily();
    $vehicle = partsVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/parts", [
        'name' => 'Timing belt kit', 'estimated_cost' => 120,
    ])->assertRedirect();

    $part = VehiclePartRequest::first();
    $item = ShoppingItem::find($part->shopping_item_id);

    // Mark the shopping row bought (empty-body PATCH toggles bought -> fires shopping.item.bought).
    actingAs($users[0])->patch("/m/shopping/items/{$item->id}")->assertRedirect();

    $part->refresh();
    expect($part->status)->toBe('bought')
        ->and($part->bought_on?->toDateString())->toBe(now()->toDateString())
        ->and($part->shopping_item_id)->toBeNull();       // link closed

    $spend = Spend::first();
    expect($spend)->not->toBeNull()
        ->and($spend->amount)->toBe(12000)                // minor units
        ->and($spend->family_id)->toBe($family->id);

    $link = VehicleExpenseLink::where('spend_id', $spend->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->part_request_id)->toBe($part->id)
        ->and($link->fuel_entry_id)->toBeNull()
        ->and($link->maintenance_history_id)->toBeNull();
});

it('records a bought part with no estimate against the car without a spend', function () {
    [, $users] = partsFamily();
    $vehicle = partsVehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/parts", ['name' => 'Wiper blades'])->assertRedirect();
    $part = VehiclePartRequest::first();
    $item = ShoppingItem::find($part->shopping_item_id);

    actingAs($users[0])->patch("/m/shopping/items/{$item->id}")->assertRedirect();

    expect($part->fresh()->status)->toBe('bought')
        ->and(Spend::count())->toBe(0)
        ->and(VehicleExpenseLink::count())->toBe(0);
});

it('links a part to its originating maintenance schedule', function () {
    [, $users] = partsFamily();
    $vehicle = partsVehicle();
    $schedule = $vehicle->maintenanceSchedules()->create([
        'name' => 'Oil change', 'type' => 'oil_change', 'interval_km' => 15000, 'created_by' => $users[0]->id,
    ]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/parts", [
        'name' => 'Oil filter', 'maintenance_schedule_id' => $schedule->id,
    ])->assertRedirect();

    expect(VehiclePartRequest::first()->maintenance_schedule_id)->toBe($schedule->id);
});

it('drops a foreign maintenance origin to a general vehicle part', function () {
    [, $usersA] = partsFamily();
    $vehicleA = partsVehicle();
    $foreignSchedule = $vehicleA->maintenanceSchedules()->create([
        'name' => 'Oil change', 'type' => 'oil_change', 'interval_km' => 15000, 'created_by' => $usersA[0]->id,
    ]);

    [, $usersB] = partsFamily();
    $vehicleB = partsVehicle();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleB->id}/parts", [
        'name' => 'Air filter', 'maintenance_schedule_id' => $foreignSchedule->id,
    ])->assertRedirect();

    // The foreign schedule id is silently dropped — the part is still created, just unlinked.
    expect(VehiclePartRequest::where('vehicle_id', $vehicleB->id)->first()->maintenance_schedule_id)->toBeNull();
});

it('keeps parts family-scoped', function () {
    [, $usersA] = partsFamily();
    $vehicleA = partsVehicle();
    actingAs($usersA[0])->post("/m/vehicles/{$vehicleA->id}/parts", ['name' => 'Spark plugs']);
    $partA = VehiclePartRequest::first();

    [, $usersB] = partsFamily();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/parts", ['name' => 'x'])->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/{$vehicleA->id}/parts/{$partA->id}")->assertNotFound();

    expect(VehiclePartRequest::withoutGlobalScopes()->count())->toBe(1);
});
