<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Notes\Note;
use Modules\Spending\Spend;
use Modules\Tasks\Task;
use Modules\Vehicles\Repair;
use Modules\Vehicles\RepairAttachment;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleExpenseLink;
use Modules\Vehicles\VehicleNoteLink;

use function Pest\Laravel\actingAs;

// A family with Vehicles + Tasks + Spending + Notes enabled — the modules P4 spans. Distinct helper name to
// avoid clashing with the other vehicles test files.
function p4Family(): array
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
    foreach (['vehicles', 'tasks', 'spending', 'notes'] as $m) {
        $registry->enable($m, $family->id);
    }

    return [$family, $users];
}

function p4Vehicle(array $attrs = []): Vehicle
{
    return Vehicle::create(array_merge([
        'make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 150000,
    ], $attrs));
}

// ---- Repairs ---------------------------------------------------------------------------------------

it('records a repair scoped to the family', function () {
    [$family, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'Replaced alternator',
        'occurred_on' => '2026-08-20',
        'mileage' => 149000,
        'cost' => 320.00,
        'provider' => 'AutoService SRL',
        'notes' => 'Whining belt, swapped the unit.',
    ])->assertRedirect();

    $repair = Repair::first();
    expect($repair)->not->toBeNull()
        ->and($repair->title)->toBe('Replaced alternator')
        ->and($repair->vehicle_id)->toBe($vehicle->id)
        ->and($repair->family_id)->toBe($family->id)
        ->and($repair->mileage)->toBe(149000)
        ->and((float) $repair->cost)->toBe(320.00)
        ->and($repair->created_by)->toBe($users[0]->id);

    // A repair without the opt-in never touches Spending.
    expect(Spend::count())->toBe(0)->and(VehicleExpenseLink::count())->toBe(0);
});

it('mirrors a costed repair into Spending when opted in, linked to the car', function () {
    [$family, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'New clutch',
        'occurred_on' => '2026-08-20',
        'cost' => 540.00,
        'create_expense' => true,
    ])->assertRedirect();

    $spend = Spend::first();
    expect($spend)->not->toBeNull()
        ->and($spend->amount)->toBe(54000)          // minor units
        ->and($spend->family_id)->toBe($family->id);

    $link = VehicleExpenseLink::where('spend_id', $spend->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->maintenance_history_id)->toBeNull();   // a repair is not tied to a maintenance record
});

it('does not create an expense for a costed repair when not opted in', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'New clutch', 'occurred_on' => '2026-08-20', 'cost' => 540.00,
    ])->assertRedirect();

    expect(Spend::count())->toBe(0)->and(VehicleExpenseLink::count())->toBe(0);
});

it('uploads a repair attachment and serves it back to the family', function () {
    Storage::fake('r2');
    [, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'Brake job',
        'occurred_on' => '2026-08-20',
        'attachments' => [
            UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg'),
        ],
    ])->assertRedirect();

    $repair = Repair::first();
    expect($repair->attachments()->count())->toBe(2);
    $attachment = $repair->attachments()->where('name', 'invoice.pdf')->first();
    expect($attachment)->not->toBeNull();
    Storage::disk('r2')->assertExists($attachment->path);

    // The auth-gated stream returns the file to a family member.
    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/repairs/{$repair->id}/attachments/{$attachment->id}")->assertOk();
});

it('trashes a repair, keeping its attachments until it is purged', function () {
    Storage::fake('r2');
    [, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/repairs", [
        'title' => 'Brake job', 'occurred_on' => '2026-08-20',
        'attachments' => [UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg')],
    ]);
    $repair = Repair::first();
    $path = $repair->attachments()->first()->path;

    actingAs($users[0])->delete("/m/vehicles/{$vehicle->id}/repairs/{$repair->id}")->assertRedirect();

    expect(Repair::count())->toBe(0)->and(RepairAttachment::count())->toBe(1);
    Storage::disk('r2')->assertExists($path); // kept for a restore

    Repair::onlyTrashed()->find($repair->id)->forceDelete(); // what trash:purge does
    expect(RepairAttachment::count())->toBe(0);
    Storage::disk('r2')->assertMissing($path);
});

it('keeps repairs family-scoped', function () {
    [, $usersA] = p4Family();
    $vehicleA = p4Vehicle();
    actingAs($usersA[0])->post("/m/vehicles/{$vehicleA->id}/repairs", ['title' => 'A', 'occurred_on' => '2026-08-20']);
    $repairA = Repair::first();

    [, $usersB] = p4Family();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/repairs")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/repairs", ['title' => 'X', 'occurred_on' => '2026-08-20'])->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/{$vehicleA->id}/repairs/{$repairA->id}")->assertNotFound();

    // Count across families (the active tenant is now family B, which would otherwise scope this to 0).
    expect(Repair::withoutGlobalScopes()->count())->toBe(1);
});

// ---- Documents -------------------------------------------------------------------------------------

it('creates a document and uploads its file', function () {
    Storage::fake('r2');
    [$family, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/documents", [
        'type' => 'insurance',
        'title' => 'RCA 2026',
        'expires_on' => '2026-12-01',
        'file' => UploadedFile::fake()->create('rca.pdf', 200, 'application/pdf'),
    ])->assertRedirect();

    $document = VehicleDocument::first();
    expect($document)->not->toBeNull()
        ->and($document->type)->toBe('insurance')
        ->and($document->title)->toBe('RCA 2026')
        ->and($document->family_id)->toBe($family->id)
        ->and($document->file_path)->not->toBeNull()
        ->and($document->file_name)->toBe('rca.pdf');
    Storage::disk('r2')->assertExists($document->file_path);

    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/documents/{$document->id}/file")->assertOk();
});

it('rejects an invalid document type', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/documents", [
        'type' => 'passport', 'title' => 'Nope',
    ])->assertSessionHasErrors('type');
});

it('creates exactly one renewal task for a document on demand and never duplicates', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle(['owner_id' => $users[1]->id]);
    $document = $vehicle->documents()->create([
        'type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(),
        'created_by' => $users[0]->id,
    ]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/documents/{$document->id}/renewal-task")->assertRedirect();

    $tasks = Task::all();
    expect($tasks)->toHaveCount(1);
    $task = $tasks->first();
    expect($task->title)->toContain('Renew')->and($task->title)->toContain('RCA 2026')
        ->and($task->assigned_to)->toBe($users[1]->id)   // the car's owner
        ->and($task->due_date->toDateString())->toBe($document->expires_on->toDateString());

    $document->refresh();
    expect($document->renewal_task_id)->toBe($task->id);

    // A second click while the task is open must not create another (idempotent via renewal_task_id).
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/documents/{$document->id}/renewal-task")->assertRedirect();
    expect(Task::count())->toBe(1);
});

it('creates exactly one renewal task for an expiring document via the daily sweep, idempotently', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();
    // Within the 30-day window -> the sweep should task it.
    $vehicle->documents()->create([
        'type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(15)->toDateString(),
        'created_by' => $users[0]->id,
    ]);

    test()->artisan('vehicles:check')->assertExitCode(0);
    expect(Task::count())->toBe(1);

    // Re-running never doubles up.
    test()->artisan('vehicles:check')->assertExitCode(0);
    expect(Task::count())->toBe(1);
});

it('does not create a renewal task for a document that is not close to expiring', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();
    $vehicle->documents()->create([
        'type' => 'insurance', 'title' => 'RCA 2027', 'expires_on' => now()->addDays(200)->toDateString(),
        'created_by' => $users[0]->id,
    ]);

    test()->artisan('vehicles:check')->assertExitCode(0);
    expect(Task::count())->toBe(0);
});

it('lets a new renewal task fire after the previous one is completed (self-healing link)', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();
    $document = $vehicle->documents()->create([
        'type' => 'insurance', 'title' => 'RCA 2026', 'expires_on' => now()->addDays(10)->toDateString(),
        'created_by' => $users[0]->id,
    ]);

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/documents/{$document->id}/renewal-task");
    $first = Task::first();
    $first->update(['done' => true]); // completed out-of-band

    // With the previous task done, the sweep may raise a fresh one (the link self-heals to null).
    test()->artisan('vehicles:check')->assertExitCode(0);
    expect(Task::where('done', false)->count())->toBe(1)
        ->and($document->fresh()->renewal_task_id)->not->toBe($first->id);
});

it('keeps documents family-scoped', function () {
    [, $usersA] = p4Family();
    $vehicleA = p4Vehicle();
    $documentA = $vehicleA->documents()->create(['type' => 'insurance', 'title' => 'A', 'created_by' => $usersA[0]->id]);

    [, $usersB] = p4Family();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/documents")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/documents/{$documentA->id}/renewal-task")->assertNotFound();
    actingAs($usersB[0])->delete("/m/vehicles/{$vehicleA->id}/documents/{$documentA->id}")->assertNotFound();

    expect(VehicleDocument::withoutGlobalScopes()->count())->toBe(1);
});

// ---- Notes -----------------------------------------------------------------------------------------

it('attaches a note to a vehicle by reusing the Notes module', function () {
    [$family, $users] = p4Family();
    $vehicle = p4Vehicle();

    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/notes", [
        'title' => 'Tyre pressures',
        'body' => 'Front 2.2, rear 2.4.',
    ])->assertRedirect();

    // A real Notes-module note is created and linked to the vehicle.
    $note = Note::first();
    expect($note)->not->toBeNull()
        ->and($note->title)->toBe('Tyre pressures')
        ->and($note->family_id)->toBe($family->id);

    $link = VehicleNoteLink::first();
    expect($link)->not->toBeNull()
        ->and($link->vehicle_id)->toBe($vehicle->id)
        ->and($link->note_id)->toBe($note->id);

    // It shows on the vehicle's notes screen.
    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/notes")->assertOk()->assertInertia(
        fn ($p) => $p->component('vehicles/notes/index')->has('notes', 1)->where('notes.0.title', 'Tyre pressures')->etc()
    );
});

it('edits and deletes a vehicle note (and its link)', function () {
    [, $users] = p4Family();
    $vehicle = p4Vehicle();
    actingAs($users[0])->post("/m/vehicles/{$vehicle->id}/notes", ['body' => 'draft']);
    $note = Note::first();

    actingAs($users[0])->patch("/m/vehicles/{$vehicle->id}/notes/{$note->id}", ['title' => 'Winter setup', 'body' => 'Swap tyres in Nov.'])->assertRedirect();
    expect($note->fresh()->title)->toBe('Winter setup');

    actingAs($users[0])->delete("/m/vehicles/{$vehicle->id}/notes/{$note->id}")->assertRedirect();
    // The note goes to the 30-day trash; its link stays (so a restore re-attaches it) but the vehicle's
    // notes page no longer lists it.
    expect(Note::count())->toBe(0)->and(VehicleNoteLink::count())->toBe(1);
    $this->assertSoftDeleted($note);
    actingAs($users[0])->get("/m/vehicles/{$vehicle->id}/notes")->assertInertia(fn ($p) => $p->has('notes', 0)->etc());
});

it('keeps vehicle notes family-scoped', function () {
    [, $usersA] = p4Family();
    $vehicleA = p4Vehicle();
    actingAs($usersA[0])->post("/m/vehicles/{$vehicleA->id}/notes", ['body' => 'secret']);
    $noteA = Note::first();

    [, $usersB] = p4Family();
    actingAs($usersB[0])->get("/m/vehicles/{$vehicleA->id}/notes")->assertNotFound();
    actingAs($usersB[0])->post("/m/vehicles/{$vehicleA->id}/notes", ['body' => 'x'])->assertNotFound();
    actingAs($usersB[0])->patch("/m/vehicles/{$vehicleA->id}/notes/{$noteA->id}", ['body' => 'hacked'])->assertNotFound();

    // Re-fetch without the (now family-B) scope: family A's note is untouched.
    expect(Note::withoutGlobalScopes()->find($noteA->id)->body)->toBe('secret');
});
