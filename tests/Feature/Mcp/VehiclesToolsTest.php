<?php

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\Spend;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleFuelEntry;
use Modules\Vehicles\VehicleMaintenanceHistory;

// Cars over MCP: read a car's full state + history, log mileage / fuel / service / repair / expense through
// the same model paths the app uses. Cars are family-wide, so the family scope is the only access rule.

function carsToken(array $access = ['vehicles' => 'write'], array $modules = ['vehicles', 'tasks', 'spending']): array
{
    [$family, $users] = mcpFamily($modules);
    CurrentFamily::set($family->id);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000, 'license_plate' => 'B 123 ABC']);
    $oil = $car->maintenanceSchedules()->create([
        'name' => 'Oil & filter', 'type' => 'oil_change', 'interval_km' => 15000, 'interval_months' => 12,
        'last_performed_mileage' => 134000, 'last_performed_at' => now()->subMonths(13)->toDateString(),
    ]);
    $car->documents()->create(['type' => 'insurance', 'title' => 'RCA', 'expires_on' => now()->addDays(10)->toDateString()]);
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    return [$family, $users, $token, $car, $oil];
}

it('lists cars and shows one car with what is due and expiring', function () {
    [, , $token, $car, $oil] = carsToken(['vehicles' => 'read']);

    [, $list] = mcpTool($token, 'vehicles_list');
    expect($list['vehicles'][0])->toMatchArray(['id' => $car->id, 'name' => 'Dacia Duster (2019)', 'current_mileage_km' => 150000, 'status' => 'attention', 'license_plate' => 'B 123 ABC'])
        ->and($list['display_unit'])->toBe('km');

    [, $got] = mcpTool($token, 'vehicles_get', ['vehicle_id' => $car->id]);
    expect($got['maintenance'][0])->toMatchArray(['schedule_id' => $oil->id, 'name' => 'Oil & filter', 'status' => 'due', 'next_due_km' => 149000])
        ->and($got['documents'][0])->toMatchArray(['title' => 'RCA', 'expiring_soon' => true]);

    // Read-only token: no write tools.
    expect(mcpToolNames($token))->toContain('vehicles_history')->not->toContain('vehicles_log_fuel');
});

it('logs mileage forward only unless it is a correction', function () {
    [, , $token, $car] = carsToken();

    [, $ok] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 151200]);
    expect($ok['current_mileage_km'])->toBe(151200);

    [$err, $msg] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 1000]);
    expect($err)->toBeTrue()->and($msg)->toContain('correction');

    [, $fixed] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 151100, 'correction' => true]);
    expect($fixed['current_mileage_km'])->toBe(151100);
});

it('logs a fill-up, moving the odometer and optionally recording the expense', function () {
    [, , $token, $car] = carsToken();

    [, $fill] = mcpTool($token, 'vehicles_log_fuel', ['vehicle_id' => $car->id, 'liters' => 42.5, 'mileage_km' => 150600, 'cost' => 300, 'station' => 'OMV', 'add_expense' => true]);
    expect($fill['expense_recorded'])->toBeTrue()->and($fill['vehicle']['current_mileage_km'])->toBe(150600)
        ->and(VehicleFuelEntry::count())->toBe(1)
        ->and(Spend::where('note', 'Fuel — OMV')->exists())->toBeTrue();

    // Without add_expense nothing goes to Spending (same default as the app's form).
    mcpTool($token, 'vehicles_log_fuel', ['vehicle_id' => $car->id, 'liters' => 40, 'mileage_km' => 151200, 'cost' => 280]);
    expect(Spend::count())->toBe(1);
});

it('completes a maintenance schedule, resetting when it is next due', function () {
    [, , $token, $car, $oil] = carsToken();

    [$err, $done] = mcpTool($token, 'vehicles_complete_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $oil->id, 'mileage_km' => 150300, 'cost' => 450, 'provider' => 'Auto Service']);
    expect($err)->toBeFalse()->and($done['completed'])->toBe('Oil & filter')->and($done['next_due_km'])->toBe(165300)
        ->and(VehicleMaintenanceHistory::count())->toBe(1)
        ->and($car->fresh()->current_mileage)->toBe(150300)
        ->and(Spend::count())->toBe(1); // a service cost always lands in Spending, as in the app

    [, $hist] = mcpTool($token, 'vehicles_history', ['vehicle_id' => $car->id, 'type' => 'maintenance']);
    expect($hist['history'][0]['title'])->toBe('Oil & filter');
});

it('records a repair and a plain car expense', function () {
    [, , $token, $car] = carsToken();

    [, $r] = mcpTool($token, 'vehicles_add_repair', ['vehicle_id' => $car->id, 'title' => 'New wiper blades', 'cost' => 80, 'add_expense' => true]);
    expect($r['expense_recorded'])->toBeTrue();

    [, $e] = mcpTool($token, 'vehicles_add_expense', ['vehicle_id' => $car->id, 'amount' => 25, 'note' => 'Car wash']);
    expect($e['amount'])->toBe('25.00')->and(Spend::pluck('note')->sort()->values()->all())->toBe(['Car wash', 'New wiper blades']);
});

it('refuses an expense when Spending is off, and never reaches another family\'s car', function () {
    [$family, , $token, $car] = carsToken(['vehicles' => 'write'], ['vehicles', 'tasks', 'spending']);
    app(ModuleRegistry::class)->disable('spending', $family->id);
    [$err, $msg] = mcpTool($token, 'vehicles_add_expense', ['vehicle_id' => $car->id, 'amount' => 10]);
    expect($err)->toBeTrue()->and($msg)->toContain('Spending');

    [$other] = mcpFamily(['vehicles']);
    CurrentFamily::set($other->id);
    $theirs = Vehicle::create(['make' => 'VW', 'model' => 'Golf', 'current_mileage' => 1000]);

    expect(mcpTool($token, 'vehicles_get', ['vehicle_id' => $theirs->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $theirs->id, 'mileage_km' => 2000])[0])->toBeTrue();
    expect($theirs->fresh()->current_mileage)->toBe(1000);
});
