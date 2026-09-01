<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Vehicles\FuelStats;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleAnalytics;
use Modules\Vehicles\VehicleExpenses;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending on (what the analytics screen spans). Distinct helper name so it
// doesn't clash with the other vehicles test files (Pest shares top-level functions across the suite).
function anFamily(): array
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

function anVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// ---- Consumption (L/100km) + cost/km -----------------------------------------------------------------

it('computes the consumption trend as one L/100km point per full-to-full segment', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle(['current_mileage' => 100000]);

    // Full @100,000 (baseline) -> full 40L @100,500 (8.0) -> full 45L @101,000 (9.0).
    $vehicle->fuelEntries()->create(['filled_on' => '2026-01-01', 'liters' => 50, 'mileage' => 100000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => '2026-02-01', 'liters' => 40, 'mileage' => 100500, 'is_full_tank' => true, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => '2026-03-01', 'liters' => 45, 'mileage' => 101000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);

    $trend = FuelStats::consumptionTrend($vehicle->fuelEntries()->get());

    // Two CLOSED segments (three full tanks), dated at the fill that closes each.
    expect($trend)->toHaveCount(2)
        ->and($trend[0]['value'])->toBe(8.0)
        ->and($trend[0]['date'])->toBe('2026-02-01')
        ->and($trend[1]['value'])->toBe(9.0)
        ->and($trend[1]['date'])->toBe('2026-03-01');

    // The average across both segments matches the headline stat (85L over 1,000km = 8.5).
    expect(FuelStats::forEntries($vehicle->fuelEntries()->get())['avgConsumption'])->toBe(8.5);
});

// ---- Per-vehicle spend totals + breakdown by type/period, currency-consistent ------------------------

it('totals per-vehicle spend and breaks it down by type, in one consistent currency', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle();
    $actor = $users[0];
    $today = now();

    // One expense of each type this month, tagged via the same link FK the real flows use.
    $hist = $vehicle->maintenanceHistory()->create(['name' => 'Oil change', 'performed_at' => $today, 'mileage' => 150000, 'created_by' => $actor->id]);
    VehicleExpenses::record($vehicle, $actor, 100, 'Oil', $today->toDateString(), $hist->id);

    $repair = $vehicle->repairs()->create(['title' => 'Clutch', 'occurred_on' => $today, 'created_by' => $actor->id]);
    VehicleExpenses::record($vehicle, $actor, 200, 'Clutch', $today->toDateString(), null, $repair->id);

    $fuel = $vehicle->fuelEntries()->create(['filled_on' => $today, 'liters' => 40, 'mileage' => 150100, 'is_full_tank' => true, 'created_by' => $actor->id]);
    VehicleExpenses::record($vehicle, $actor, 50, 'Fuel', $today->toDateString(), null, null, $fuel->id);

    $part = $vehicle->partRequests()->create(['name' => 'Brake pads', 'status' => 'bought', 'created_by' => $actor->id]);
    VehicleExpenses::record($vehicle, $actor, 40, 'Pads', $today->toDateString(), null, null, null, $part->id);

    // An ad-hoc expense (no typed FK) -> "other".
    VehicleExpenses::record($vehicle, $actor, 30, 'Car wash', $today->toDateString());

    // And one LAST YEAR that must fall outside the month-to-date window but inside all-time.
    VehicleExpenses::record($vehicle, $actor, 500, 'Old service', $today->copy()->subYear()->toDateString(), $hist->id);

    // Month-to-date: only this month's five expenses (100+200+50+40+30 = €420).
    $mtd = VehicleAnalytics::forVehicle($vehicle, $today->copy()->startOfMonth(), $today->copy());
    expect($mtd['currency'])->toBe('EUR')
        ->and($mtd['total'])->toBe(42000)
        ->and($mtd['count'])->toBe(5);

    $byType = collect($mtd['byType'])->keyBy('key');
    expect($byType['maintenance']['amount'])->toBe(10000)
        ->and($byType['repair']['amount'])->toBe(20000)
        ->and($byType['fuel']['amount'])->toBe(5000)
        ->and($byType['parts']['amount'])->toBe(4000)
        ->and($byType['other']['amount'])->toBe(3000);
    // Percentages are of the period total and the rows are sorted biggest-first.
    expect($mtd['byType'][0]['key'])->toBe('repair')
        ->and($byType['repair']['pct'])->toBe(48); // 200/420

    // All-time spans the last-year service too (420 + 500 = €920).
    [, $start, $end] = VehicleAnalytics::range($vehicle, 'all_time');
    expect(VehicleAnalytics::forVehicle($vehicle, $start, $end)['total'])->toBe(92000);
});

// ---- Operating cost per km ----------------------------------------------------------------------------

it('computes operating cost per km as period spend over distance driven', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle(['current_mileage' => 100000]);
    $actor = $users[0];
    $today = now();

    // Two odometer readings inside the month: 100,000 at the month's start then 101,000 now -> 1,000 km.
    // (Anchored at startOfMonth()/now() rather than an offset, so this holds even on the 1st of the month.)
    $vehicle->mileageLogs()->create(['mileage' => 100000, 'recorded_by' => $actor->id, 'recorded_at' => $today->copy()->startOfMonth()]);
    $vehicle->mileageLogs()->create(['mileage' => 101000, 'recorded_by' => $actor->id, 'recorded_at' => $today->copy()]);

    // €200 spent this month.
    VehicleExpenses::record($vehicle, $actor, 200, 'Service', $today->toDateString());

    $a = VehicleAnalytics::forVehicle($vehicle, $today->copy()->startOfMonth(), $today->copy());
    // 20,000 cents / 1,000 km = 20.0 cents per km.
    expect($a['kmDriven'])->toBe(1000)
        ->and($a['costPerKm'])->toBe(20.0);
});

it('leaves cost per km unmeasurable when there is not enough odometer history', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle();
    VehicleExpenses::record($vehicle, $users[0], 100, 'Service', now()->toDateString());

    $a = VehicleAnalytics::forVehicle($vehicle, now()->copy()->startOfMonth(), now()->copy());
    expect($a['kmDriven'])->toBeNull()
        ->and($a['costPerKm'])->toBeNull();
});

// ---- Estimations (exist + sane, clearly labelled) ----------------------------------------------------

it('produces sane, derived next-service and next-fill estimates', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle(['current_mileage' => 149000]);

    // A schedule with a future km trigger (last done at 140,000, every 15,000 -> next 155,000).
    $vehicle->maintenanceSchedules()->create([
        'name' => 'Oil change', 'type' => 'oil_change', 'interval_km' => 15000,
        'last_performed_mileage' => 140000, 'last_performed_at' => now()->subMonths(2), 'created_by' => $users[0]->id,
    ]);

    // Two fills a week apart -> a ~7-day interval, 500 km between them.
    $vehicle->fuelEntries()->create(['filled_on' => now()->copy()->subDays(14)->toDateString(), 'liters' => 40, 'mileage' => 149000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);
    $vehicle->fuelEntries()->create(['filled_on' => now()->copy()->subDays(7)->toDateString(), 'liters' => 40, 'mileage' => 149500, 'is_full_tank' => true, 'created_by' => $users[0]->id]);

    $est = VehicleAnalytics::estimates($vehicle);

    expect($est['nextService'])->not->toBeNull()
        ->and($est['nextService']['name'])->toBe('Oil change')
        ->and($est['nextService']['remainingKm'])->toBe(6000);  // 155,000 - 149,000

    expect($est['nextFuelFill'])->not->toBeNull()
        ->and($est['nextFuelFill']['days'])->toBeGreaterThanOrEqual(0)
        ->and($est['nextFuelFill']['avgKmBetweenFills'])->toBe(500)
        ->and($est['nextFuelFill']['estimatedDate'])->toBeString();
});

it('has no next-fill estimate with fewer than two fills', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle();
    $vehicle->fuelEntries()->create(['filled_on' => now()->toDateString(), 'liters' => 40, 'mileage' => 150000, 'is_full_tank' => true, 'created_by' => $users[0]->id]);

    expect(VehicleAnalytics::estimates($vehicle)['nextFuelFill'])->toBeNull();
});

// ---- Screen + family scoping --------------------------------------------------------------------------

it('renders the analytics screen and scopes it to the family', function () {
    [, $usersA] = anFamily();
    $vehicleA = anVehicle();

    actingAs($usersA[0])->get("/m/vehicles/{$vehicleA->id}/analytics")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/analytics/index')
            ->has('analytics')->has('fuel')->has('consumptionTrend')->has('estimates')
            ->where('period', 'mtd')->etc()
    );

    // A member of another family can't see this car's costs.
    [, $usersB] = anFamily();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/analytics")->assertNotFound();
});

it('feeds the compact running-cost headline into the Overview', function () {
    [, $users] = anFamily();
    $vehicle = anVehicle();
    VehicleExpenses::record($vehicle, $users[0], 250, 'Service', now()->toDateString());

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->where('analytics.ytdTotal', 25000)
            ->where('analytics.hasData', true)
            ->where('analytics.currency', 'EUR')->etc()
    );
});
