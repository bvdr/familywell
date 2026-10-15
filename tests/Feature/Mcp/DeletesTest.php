<?php

use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Storage;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;
use Modules\Inventory\InventoryItem;
use Modules\Projects\Project;
use Modules\Vehicles\Repair;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleMileageLog;

// Delete tools for contacts, birthdays, inventory, projects and vehicles: marked destructive, each runs the
// same path as the app's delete button, and lands in the 30-day trash (stored files are only freed at purge).

function deleteToken(array $modules): array
{
    [$family, $users] = mcpFamily($modules, 2);
    CurrentFamily::set($family->id);
    $token = mcpConnect($users[0], $family->id, array_fill_keys($modules, 'write'))['access_token'];

    return [$family, $users, $token];
}

it('removes a duplicate mileage reading, and falls back when the newest one goes', function () {
    [, $users, $token] = deleteToken(['vehicles']);
    $car = Vehicle::create(['make' => 'VW', 'model' => 'Golf', 'current_mileage' => 85162]);
    $car->recordMileage(87634, $users[0]);
    $this->travel(1)->minutes();
    $car->recordMileage(87634, $users[0]); // the duplicate from the screenshot
    [$first, $dup] = VehicleMileageLog::orderBy('id')->get();

    [$err, $r] = mcpTool($token, 'vehicles_delete_record', ['vehicle_id' => $car->id, 'type' => 'mileage', 'id' => $dup->id]);
    expect($err)->toBeFalse()->and($r['vehicle']['current_mileage_km'])->toBe(87634)
        ->and(VehicleMileageLog::count())->toBe(1);

    // Deleting the newest remaining reading when it is the odometer value: falls back to the reading before.
    $this->travel(1)->minutes();
    $car->fresh()->recordMileage(90000, $users[0]);
    $newest = VehicleMileageLog::latest('id')->first();
    [, $r] = mcpTool($token, 'vehicles_delete_record', ['vehicle_id' => $car->id, 'type' => 'mileage', 'id' => $newest->id]);
    expect($r['vehicle']['current_mileage_km'])->toBe(87634);

    // Restoring it undoes the fallback: it is the newest reading again, so the odometer moves back up.
    [$err] = mcpTool($token, 'vehicles_restore', ['type' => 'mileage', 'id' => $newest->id]);
    expect($err)->toBeFalse()->and($car->fresh()->current_mileage)->toBe(90000);
});

it('trashes repairs, documents, fuel fills and schedules, restores one, and frees the files at purge', function () {
    Storage::fake('r2');
    [, $users, $token] = deleteToken(['vehicles']);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 1000]);
    $pdf = base64_encode("%PDF-1.4\n%%EOF\n");
    [, $repair] = mcpTool($token, 'vehicles_add_repair', ['vehicle_id' => $car->id, 'title' => 'Brakes', 'file_base64' => $pdf, 'file_name' => 'f.pdf']);
    [, $doc] = mcpTool($token, 'vehicles_add_document', ['vehicle_id' => $car->id, 'type' => 'insurance', 'title' => 'RCA', 'file_base64' => $pdf, 'file_name' => 'rca.pdf']);
    $repairFile = Repair::find($repair['repair_id'])->attachments()->value('path');
    $docFile = VehicleDocument::find($doc['document_id'])->file_path;
    $fill = $car->logFuel(['filled_on' => '2026-10-01', 'liters' => 40, 'mileage' => 1200], $users[0]);
    $schedule = $car->maintenanceSchedules()->create(['name' => 'Oil', 'type' => 'oil_change', 'interval_km' => 15000]);

    foreach ([['repair', $repair['repair_id']], ['document', $doc['document_id']], ['fuel', $fill->id], ['maintenance_schedule', $schedule->id]] as [$type, $id]) {
        [$err] = mcpTool($token, 'vehicles_delete_record', ['vehicle_id' => $car->id, 'type' => $type, 'id' => $id]);
        expect($err)->toBeFalse();
    }
    Storage::disk('r2')->assertExists($repairFile); // kept for a restore
    Storage::disk('r2')->assertExists($docFile);
    expect($car->repairs()->count() + $car->documents()->count() + $car->fuelEntries()->count() + $car->maintenanceSchedules()->count())->toBe(0);

    [, $trash] = mcpTool($token, 'vehicles_list_deleted');
    expect(collect($trash['deleted'])->pluck('type')->sort()->values()->all())->toBe(['document', 'fuel', 'maintenance_schedule', 'repair'])
        ->and(collect($trash['deleted'])->pluck('where')->unique()->all())->toBe(['Dacia Duster']);
    [$err, $r] = mcpTool($token, 'vehicles_restore', ['type' => 'document', 'id' => $doc['document_id']]);
    expect($err)->toBeFalse()->and($r['url'])->toEndWith("/m/vehicles/{$car->id}/documents")
        ->and($car->documents()->count())->toBe(1);

    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertExitCode(0);
    Storage::disk('r2')->assertMissing($repairFile);
    Storage::disk('r2')->assertExists($docFile); // restored, so it stays
    expect(Repair::withTrashed()->find($repair['repair_id']))->toBeNull();
});

it('trashes a whole car with its records, restores it, and frees its files at purge', function () {
    Storage::fake('r2');
    [, , $token] = deleteToken(['vehicles']);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 1000]);
    [, $doc] = mcpTool($token, 'vehicles_add_document', ['vehicle_id' => $car->id, 'type' => 'insurance', 'title' => 'RCA', 'file_base64' => base64_encode("%PDF-1.4\n%%EOF\n"), 'file_name' => 'rca.pdf']);
    $file = VehicleDocument::find($doc['document_id'])->file_path;

    [$err] = mcpTool($token, 'vehicles_delete', ['vehicle_id' => $car->id]);
    expect($err)->toBeFalse()->and(Vehicle::find($car->id))->toBeNull();
    Storage::disk('r2')->assertExists($file);
    [, $trash] = mcpTool($token, 'vehicles_list_deleted');
    expect(collect($trash['deleted'])->pluck('type')->all())->toBe(['vehicle']); // its records ride along

    [$err] = mcpTool($token, 'vehicles_restore', ['type' => 'vehicle', 'id' => $car->id]);
    expect($err)->toBeFalse()->and(mcpTool($token, 'vehicles_get', ['vehicle_id' => $car->id])[1]['documents'])->toHaveCount(1);

    mcpTool($token, 'vehicles_delete', ['vehicle_id' => $car->id]);
    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertExitCode(0);
    Storage::disk('r2')->assertMissing($file);
    expect(Vehicle::withTrashed()->find($car->id))->toBeNull()->and(VehicleDocument::withTrashed()->count())->toBe(0);

    $tools = collect(mcpCall($token, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($tools['vehicles_delete']['annotations']['destructiveHint'] ?? null)->toBeTrue()
        ->and($tools['vehicles_delete_record']['annotations']['destructiveHint'] ?? null)->toBeTrue();
});

it('gives every tool a title and explicit readOnlyHint, destructiveHint and openWorldHint, as the Claude and ChatGPT reviews ask', function () {
    [, , $token] = deleteToken(['vehicles', 'notes']);
    $tools = collect(mcpCall($token, 'tools/list')->json('result.tools'))->keyBy('name');

    foreach ($tools as $name => $tool) {
        $a = $tool['annotations'];
        expect($a['title'] ?? '')->not->toBe('', $name)
            ->and($a)->toHaveKeys(['readOnlyHint', 'destructiveHint', 'openWorldHint']);
    }
    expect($tools['notes_list']['annotations'])->toMatchArray(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false])
        ->and($tools['vehicles_log_mileage']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['notes_create']['annotations'])->toMatchArray(['title' => 'Create Note', 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false])
        ->and($tools['notes_update']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['notes_delete']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['vehicles_add_document']['annotations']['openWorldHint'])->toBeTrue();
});

it('deletes contacts (with their birthday), birthdays and inventory items', function () {
    [, , $token] = deleteToken(['contacts', 'birthdays', 'inventory']);
    [, $b] = mcpTool($token, 'birthdays_add', ['name' => 'Ana', 'birth_day' => 3, 'birth_month' => 10]);
    [, $c] = mcpTool($token, 'contacts_create', ['name' => 'Bob']);
    [, $i] = mcpTool($token, 'inventory_add_item', ['name' => 'Rice', 'unit' => 'kg']);

    $ana = BirthdayDate::find($b['id'])->person_id;
    expect(mcpTool($token, 'birthdays_delete', ['birthday_id' => $b['id']])[0])->toBeFalse()
        ->and(BirthdayDate::find($b['id']))->toBeNull()->and(Person::find($ana))->not->toBeNull() // contact stays
        ->and(BirthdayDate::onlyTrashed()->find($b['id']))->not->toBeNull(); // in the 30-day trash

    expect(mcpTool($token, 'contacts_delete', ['person_id' => $c['id'] ?? $c['person_id']])[0])->toBeFalse()
        ->and(Person::where('name', 'Bob')->exists())->toBeFalse();

    expect(mcpTool($token, 'inventory_delete_item', ['item_id' => $i['id']])[0])->toBeFalse()
        ->and(InventoryItem::find($i['id']))->toBeNull()->and(InventoryItem::onlyTrashed()->find($i['id']))->not->toBeNull();
});

it('lets only a project\'s creator delete it', function () {
    [, $users, $token] = deleteToken(['projects']);
    [, $mine] = mcpTool($token, 'projects_create', ['name' => 'Garage']);
    $theirs = Project::createFor($users[1], ['name' => 'Their garden']);

    [$err, $msg] = mcpTool($token, 'projects_delete', ['project_id' => $theirs->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('created this project')->and(Project::find($theirs->id))->not->toBeNull();

    expect(mcpTool($token, 'projects_delete', ['project_id' => $mine['id'] ?? $mine['project_id']])[0])->toBeFalse()
        ->and(Project::where('name', 'Garage')->exists())->toBeFalse()
        ->and(Project::onlyTrashed()->where('name', 'Garage')->exists())->toBeTrue(); // in the 30-day trash
});
