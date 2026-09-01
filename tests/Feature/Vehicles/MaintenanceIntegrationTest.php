<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\Spend;
use Modules\Tasks\Task;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExpenseLink;
use Modules\Vehicles\VehicleMaintenanceHistory;
use Modules\Vehicles\VehicleMaintenanceSchedule;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending enabled — the three modules the P3 round-trips span. Distinct
// helper name to avoid clashing with the other vehicles test files' helpers.
function integFamily(): array
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
    $registry->enable('vehicles', $family->id);
    $registry->enable('tasks', $family->id);
    $registry->enable('spending', $family->id);

    return [$family, $users];
}

function integVehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// An OVERDUE km schedule (next due 145,000 km, car at 150,000) — needs attention, so the sweep tasks it.
function dueSchedule(Vehicle $vehicle, array $attrs = []): VehicleMaintenanceSchedule
{
    return $vehicle->maintenanceSchedules()->create(array_merge([
        'name' => 'Oil & filter', 'type' => 'oil_change',
        'interval_km' => 15000, 'interval_months' => null,
        'last_performed_mileage' => 130000, 'last_performed_at' => null,
    ], $attrs));
}

it('creates exactly one task for a due schedule and never duplicates on re-run', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle(['owner_id' => $users[1]->id]);
    $schedule = dueSchedule($vehicle);

    test()->artisan('vehicles:check')->assertExitCode(0);

    $tasks = Task::all();
    expect($tasks)->toHaveCount(1);
    $task = $tasks->first();
    expect($task->title)->toContain('Oil & filter')
        ->and($task->assigned_to)->toBe($users[1]->id)   // defaults to the car's owner
        ->and($task->done)->toBeFalse();

    $schedule->refresh();
    expect($schedule->open_task_id)->toBe($task->id);

    // Re-running the sweep must not spawn a second task (idempotent via open_task_id).
    test()->artisan('vehicles:check')->assertExitCode(0);
    expect(Task::count())->toBe(1);
});

it('does not create a task for a schedule that is not yet due', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle();
    // Next due at 160,000 km, car at 150,000 — good, not due.
    dueSchedule($vehicle, ['last_performed_mileage' => 145000]);

    test()->artisan('vehicles:check')->assertExitCode(0);

    expect(Task::count())->toBe(0);
});

it('completes the maintenance when the linked task is checked off: history, advance, next, cleared link', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle(['current_mileage' => 150000]);
    $schedule = dueSchedule($vehicle);

    test()->artisan('vehicles:check')->assertExitCode(0);
    $task = Task::first();

    // Check it off in the Tasks module (empty-body PATCH = toggle done). The task.saved hook completes it.
    actingAs($users[0])->patch("/m/tasks/tasks/{$task->id}")->assertRedirect();

    // History written at the car's current odometer.
    $history = VehicleMaintenanceHistory::where('schedule_id', $schedule->id)->first();
    expect($history)->not->toBeNull()->and($history->mileage)->toBe(150000);

    // Baseline advanced -> next due recomputes to 165,000 km, status good again.
    $schedule->refresh();
    expect($schedule->last_performed_mileage)->toBe(150000)
        ->and($schedule->open_task_id)->toBeNull();          // link cleared, so it can fire next cycle
    $status = $schedule->statusFor(150000);
    expect($status['nextDueMileage'])->toBe(165000)->and($status['status'])->toBe('good');

    // The task itself is done.
    expect($task->fresh()->done)->toBeTrue();
});

it('records a Spending expense linked to the car when a completion carries a cost, and closes the task', function () {
    [$family, $users] = integFamily();
    $vehicle = integVehicle(['current_mileage' => 150000]);
    $schedule = dueSchedule($vehicle);

    test()->artisan('vehicles:check')->assertExitCode(0);
    $task = Task::first();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-09-01', 'mileage' => 150000, 'cost' => 380.50, 'provider' => 'AutoService SRL',
    ])->assertRedirect();

    // A real Spend exists (EUR default, amount in minor units) and is linked to the vehicle + performance.
    $spend = Spend::first();
    expect($spend)->not->toBeNull()
        ->and($spend->amount)->toBe(38050)
        ->and($spend->family_id)->toBe($family->id);

    $history = VehicleMaintenanceHistory::where('schedule_id', $schedule->id)->first();
    $link = VehicleExpenseLink::where('spend_id', $spend->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->maintenance_history_id)->toBe($history->id);

    // Completing via the Vehicles UI also closes the linked task and clears the schedule link.
    expect($task->fresh()->done)->toBeTrue()
        ->and($schedule->fresh()->open_task_id)->toBeNull();
});

it('does not create an expense when the completion has no cost', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle();
    $schedule = dueSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/complete", [
        'performed_at' => '2026-09-01', 'mileage' => 150000,
    ])->assertRedirect();

    expect(Spend::count())->toBe(0)->and(VehicleExpenseLink::count())->toBe(0);
});

it('creates the linked task on demand and is idempotent', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle();
    $schedule = dueSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/task")->assertRedirect();
    expect(Task::count())->toBe(1);
    $taskId = $schedule->fresh()->open_task_id;
    expect($taskId)->not->toBeNull();

    // A second click while the task is open must not create another.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/task")->assertRedirect();
    expect(Task::count())->toBe(1)
        ->and($schedule->fresh()->open_task_id)->toBe($taskId);
});

it('adds an ad-hoc expense linked to the car from the maintenance detail', function () {
    [, $users] = integFamily();
    $vehicle = integVehicle();
    $schedule = dueSchedule($vehicle);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}/expense", [
        'amount' => 42, 'note' => 'Wiper blades',
    ])->assertRedirect();

    $spend = Spend::first();
    expect($spend)->not->toBeNull()->and($spend->amount)->toBe(4200)->and($spend->note)->toBe('Wiper blades');

    $link = VehicleExpenseLink::where('spend_id', $spend->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->maintenance_history_id)->toBeNull();   // ad-hoc, not tied to a performance
});

it('keeps tasks and expenses family-scoped', function () {
    [$familyA, $usersA] = integFamily();
    $vehicleA = integVehicle();
    $scheduleA = dueSchedule($vehicleA);
    test()->artisan('vehicles:check')->assertExitCode(0);
    $taskA = Task::first();

    // A second family + user B cannot reach family A's schedule to task/expense it.
    [, $usersB] = integFamily();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/maintenance/{$scheduleA->id}/task")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/maintenance/{$scheduleA->id}/expense", ['amount' => 10])->assertNotFound();

    // And user B can't complete family A's maintenance by poking task A: the cross-family toggle is refused
    // (403/404, depending on bind order), so the schedule stays open and no history is written for it.
    actingAs($usersB[0])->patch("/m/tasks/tasks/{$taskA->id}");
    expect($scheduleA->fresh()->open_task_id)->toBe($taskA->id)
        ->and(VehicleMaintenanceHistory::where('schedule_id', $scheduleA->id)->count())->toBe(0);
});
