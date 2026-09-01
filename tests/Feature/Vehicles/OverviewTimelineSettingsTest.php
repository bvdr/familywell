<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Notification;
use Modules\Notifications\PushAlert;
use Modules\Tasks\Task;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExpenseLink;
use Modules\Vehicles\VehicleMaintenanceHistory;
use Modules\Vehicles\VehicleMaintenanceSchedule;
use Modules\Vehicles\VehicleMileageLog;
use Modules\Vehicles\VehicleSetting;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending enabled (the modules P5 spans). `members` controls the roster
// so notification-count assertions stay deterministic (a one-member family => one recipient per push).
function p5Family(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
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

function p5Vehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// An overdue schedule: last done far below the current odometer, so the km trigger is well past due.
function overdueSchedule(Vehicle $vehicle): VehicleMaintenanceSchedule
{
    return VehicleMaintenanceSchedule::create([
        'vehicle_id' => $vehicle->id, 'name' => 'Oil change', 'type' => 'oil_change',
        'interval_km' => 15000, 'last_performed_mileage' => 100000, 'active' => true,
    ]);
}

// ---- Overview roll-up ------------------------------------------------------------------------------

it('rolls a healthy car up to "good"', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();
    // Comfortably before the next service (remaining 15,000 km) and no documents.
    VehicleMaintenanceSchedule::create([
        'vehicle_id' => $vehicle->id, 'name' => 'Oil change', 'type' => 'oil_change',
        'interval_km' => 15000, 'last_performed_mileage' => 150000, 'active' => true,
    ]);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->where('status.overall', 'good')
            ->where('status.maintenanceAttention', 0)
            ->where('status.documentsExpiring', 0)
            ->etc()
    );
});

it('rolls a car with an overdue service up to "attention"', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();
    overdueSchedule($vehicle);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->where('status.overall', 'attention')
            ->where('status.maintenanceAttention', 1)
            ->where('next.maintenance.name', 'Oil change')
            ->etc()
    );
});

it('rolls a car with an expiring document up to "attention"', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();
    $vehicle->documents()->create([
        'type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(),
        'created_by' => $users[0]->id,
    ]);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->where('status.overall', 'attention')
            ->where('status.documentsExpiring', 1)
            ->where('next.document.title', 'RCA 2026')
            ->etc()
    );
});

it('surfaces recent maintenance + repair events with cost on the overview', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();
    $vehicle->maintenanceHistory()->create([
        'name' => 'Oil change', 'performed_at' => now()->subDays(3)->toDateString(), 'mileage' => 149000, 'cost' => 80,
        'created_by' => $users[0]->id,
    ]);
    $vehicle->repairs()->create([
        'title' => 'New clutch', 'occurred_on' => now()->subDay()->toDateString(), 'cost' => 540, 'created_by' => $users[0]->id,
    ]);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/overview')
            ->has('recent', 2)
            ->where('recent.0.type', 'repair')          // newest first
            ->where('recent.0.cost', 540)               // JSON drops the trailing .0
            ->where('recent.1.type', 'maintenance')
            ->etc()
    );
});

// ---- Timeline --------------------------------------------------------------------------------------

it('merges every event type into one feed, newest first', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();

    VehicleMileageLog::create(['vehicle_id' => $vehicle->id, 'mileage' => 150000, 'recorded_by' => $users[0]->id, 'recorded_at' => now()->subDays(10)]);
    $vehicle->maintenanceHistory()->create(['name' => 'Oil change', 'performed_at' => now()->subDays(8)->toDateString(), 'mileage' => 149000, 'cost' => 80, 'created_by' => $users[0]->id]);
    $vehicle->repairs()->create(['title' => 'New clutch', 'occurred_on' => now()->subDays(5)->toDateString(), 'cost' => 540, 'created_by' => $users[0]->id]);
    $vehicle->documents()->create(['type' => 'insurance', 'title' => 'RCA 2026', 'created_by' => $users[0]->id]);
    // An ad-hoc expense via the endpoint -> a standalone timeline expense entry.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/expense", ['amount' => 25, 'note' => 'Car wash', 'spent_at' => now()->subDay()->toDateString()])->assertRedirect();

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/timeline")->assertOk()->assertInertia(function ($p) {
        $p->component('vehicles/timeline')->has('entries', 5);
        $types = collect($p->toArray()['props']['entries'])->pluck('type');
        expect($types->unique()->sort()->values()->all())->toBe(['document', 'expense', 'maintenance', 'mileage', 'repair']);
        // Newest-first: the document was filed today (created_at), the mileage reading was 10 days ago.
        expect($types->first())->toBe('document');
        expect($types->last())->toBe('mileage');
    });
});

it('shows a repair cost once (as the repair), not doubled as an expense', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();

    // A repair that opts into mirroring its cost to Spending -> a linked spend tagged with repair_id.
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'New clutch', 'occurred_on' => now()->subDay()->toDateString(), 'cost' => 540, 'create_expense' => true,
    ])->assertRedirect();

    expect(VehicleExpenseLink::whereNotNull('repair_id')->count())->toBe(1);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/timeline")->assertOk()->assertInertia(function ($p) {
        $entries = collect($p->toArray()['props']['entries']);
        expect($entries->where('type', 'repair'))->toHaveCount(1);
        expect($entries->where('type', 'expense'))->toHaveCount(0);   // NOT double-counted
    });
});

it('keeps the timeline family-scoped', function () {
    [, $usersA] = p5Family();
    $vehicleA = p5Vehicle();

    [, $usersB] = p5Family();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/timeline")->assertNotFound();
});

// ---- Dashboard widget ------------------------------------------------------------------------------

it('surfaces only cars that need attention in the widget feed', function () {
    [, $users] = p5Family();
    $healthy = p5Vehicle(['make' => 'Opel', 'model' => 'Insignia']);
    VehicleMaintenanceSchedule::create(['vehicle_id' => $healthy->id, 'name' => 'Oil', 'type' => 'oil_change', 'interval_km' => 15000, 'last_performed_mileage' => 150000, 'active' => true]);
    $needy = p5Vehicle(['make' => 'Dacia', 'model' => 'Duster']);
    overdueSchedule($needy);
    $needy->documents()->create(['type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(), 'created_by' => $users[0]->id]);

    actingAs($users[0])->getJson('/m/vehicles/widget')->assertOk()->assertJson(fn ($json) => $json->has('cars', 1)
        ->where('cars.0.name', 'Dacia Duster')
        ->where('cars.0.attention', 2)          // 1 overdue service + 1 expiring document
        ->has('cars.0.items', 2)
        ->etc());
});

it('returns an empty widget feed when nothing needs attention', function () {
    [, $users] = p5Family();
    $vehicle = p5Vehicle();
    VehicleMaintenanceSchedule::create(['vehicle_id' => $vehicle->id, 'name' => 'Oil', 'type' => 'oil_change', 'interval_km' => 15000, 'last_performed_mileage' => 150000, 'active' => true]);

    actingAs($users[0])->getJson('/m/vehicles/widget')->assertOk()->assertExactJson(['cars' => []]);
});

it('404s the widget feed when the module is disabled', function () {
    [$family, $users] = p5Family();
    app(ModuleRegistry::class)->disable('vehicles', $family->id);

    actingAs($users[0])->getJson('/m/vehicles/widget')->assertNotFound();
});

it('shows widget mileage in the family unit system', function () {
    [$family, $users] = p5Family();
    VehicleSetting::current($family->id)->update(['unit_system' => 'imperial']);
    $vehicle = p5Vehicle(['current_mileage' => 150000]);
    overdueSchedule($vehicle);

    // 150,000 km ≈ 93,206 mi.
    actingAs($users[0])->getJson('/m/vehicles/widget')->assertOk()->assertJson(fn ($json) => $json->where('cars.0.mileageText', '93,206 mi')->etc());
});

// ---- vehicles:check notifications ------------------------------------------------------------------

it('vehicles:check dispatches maintenance + document notifications without double-firing', function () {
    Notification::fake();
    [, $users] = p5Family(1);          // one-member family: one recipient per push
    $vehicle = p5Vehicle();
    overdueSchedule($vehicle);
    $vehicle->documents()->create(['type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(), 'created_by' => $users[0]->id]);

    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 2);      // 1 maintenance + 1 document
    expect(Task::count())->toBe(2);

    // Same-day re-run: idempotent (open tasks already exist), no new pushes or tasks.
    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 2);
    expect(Task::count())->toBe(2);
});

it('vehicles:check still creates the task but sends no push when the family muted the condition', function () {
    Notification::fake();
    [$family, $users] = p5Family(1);
    VehicleSetting::current($family->id)->update(['notify_maintenance' => false, 'notify_documents' => false]);
    $vehicle = p5Vehicle();
    overdueSchedule($vehicle);
    $vehicle->documents()->create(['type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(), 'created_by' => $users[0]->id]);

    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 0);      // pushes muted
    expect(Task::count())->toBe(2);                          // but the actionable tasks are still raised
});

it('vehicles:check fires a mileage-milestone push only when enabled, once per milestone', function () {
    Notification::fake();
    [$family, $users] = p5Family(1);
    $vehicle = p5Vehicle(['current_mileage' => 150000]);   // sits on the 150,000 km milestone

    // Off by default -> no milestone push, and nothing recorded.
    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 0);
    expect($vehicle->fresh()->milestone_notified_km)->toBe(0);

    // Enable it -> one push, and the marker advances so a re-run won't double-fire.
    VehicleSetting::current($family->id)->update(['notify_mileage_milestone' => true]);
    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 1);
    expect($vehicle->fresh()->milestone_notified_km)->toBe(150000);

    test()->artisan('vehicles:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 1);      // no double-fire
});

// ---- Settings --------------------------------------------------------------------------------------

it('renders vehicle settings with defaults and the admin flag', function () {
    [, $users] = p5Family();

    actingAs($users[0])->get('/m/vehicles/settings')->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/settings')
            ->where('settings.unitSystem', 'metric')
            ->where('settings.notifyMaintenance', true)
            ->where('settings.notifyMileageMilestone', false)
            ->has('settings.templates')
            ->where('canManage', true)
            ->has('unitSystems')
            ->etc()
    );
});

it('lets an admin change settings and persists only the fields sent', function () {
    [$family, $users] = p5Family();

    actingAs($users[0])->patch('/m/vehicles/settings', ['notify_documents' => false])->assertRedirect();
    $s = VehicleSetting::current($family->id);
    expect($s->notify_documents)->toBeFalse()
        ->and($s->notify_maintenance)->toBeTrue()       // untouched
        ->and($s->unit_system)->toBe('metric');         // untouched

    actingAs($users[0])->patch('/m/vehicles/settings', ['unit_system' => 'imperial'])->assertRedirect();
    expect(VehicleSetting::current($family->id)->fresh()->unit_system)->toBe('imperial');
});

it('merges custom template intervals and resets them', function () {
    [$family, $users] = p5Family();

    // Customise two templates in separate patches -> both persist (merge, not replace).
    actingAs($users[0])->patch('/m/vehicles/settings', ['template_intervals' => ['oil_change' => ['km' => 10000, 'months' => 6]]])->assertRedirect();
    actingAs($users[0])->patch('/m/vehicles/settings', ['template_intervals' => ['tires' => ['km' => 40000, 'months' => null]]])->assertRedirect();

    $s = VehicleSetting::current($family->id)->fresh();
    expect($s->template_intervals['oil_change'])->toBe(['km' => 10000, 'months' => 6])
        ->and($s->template_intervals['tires']['km'])->toBe(40000);
    expect($s->templateInterval('oil_change'))->toBe(['km' => 10000, 'months' => 6]);

    // Reset one -> it falls back to the shipped default, the other stays.
    actingAs($users[0])->patch('/m/vehicles/settings', ['template_reset' => 'oil_change'])->assertRedirect();
    $s = VehicleSetting::current($family->id)->fresh();
    expect($s->template_intervals)->not->toHaveKey('oil_change')
        ->and($s->template_intervals)->toHaveKey('tires');
    expect($s->templateInterval('oil_change'))->toBe(['km' => 15000, 'months' => 12]);   // shipped default
});

it('rejects an unknown unit system and blocks a non-admin from writing settings', function () {
    [, $users] = p5Family(2);   // users[1] is a plain member

    actingAs($users[0])->patch('/m/vehicles/settings', ['unit_system' => 'furlongs'])->assertSessionHasErrors('unit_system');

    actingAs($users[1])->get('/m/vehicles/settings')->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false)->etc());
    actingAs($users[1])->patch('/m/vehicles/settings', ['notify_maintenance' => false])->assertForbidden();
});

it('feeds the maintenance form the family custom default intervals', function () {
    [$family, $users] = p5Family();
    VehicleSetting::current($family->id)->update(['template_intervals' => ['oil_change' => ['km' => 12000, 'months' => 6]]]);
    $vehicle = p5Vehicle();

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/maintenance/new")->assertOk()->assertInertia(function ($p) {
        $oil = collect($p->toArray()['props']['templates'])->firstWhere('key', 'oil_change');
        expect($oil['intervalKm'])->toBe(12000)->and($oil['intervalMonths'])->toBe(6);
    });
});
