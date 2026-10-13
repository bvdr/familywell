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

// Delete tools for records that have no trash: permanent, marked destructive, and each runs the same path
// as the app's delete button (stored files freed first).

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
});

it('deletes repairs, documents, fuel fills and schedules, freeing their files', function () {
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
    Storage::disk('r2')->assertMissing($repairFile);
    Storage::disk('r2')->assertMissing($docFile);
    expect($car->repairs()->count() + $car->documents()->count() + $car->fuelEntries()->count() + $car->maintenanceSchedules()->count())->toBe(0);
});

it('deletes a whole car with its files, and the delete tools are marked destructive', function () {
    Storage::fake('r2');
    [, , $token] = deleteToken(['vehicles']);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 1000]);
    [, $doc] = mcpTool($token, 'vehicles_add_document', ['vehicle_id' => $car->id, 'type' => 'insurance', 'title' => 'RCA', 'file_base64' => base64_encode("%PDF-1.4\n%%EOF\n"), 'file_name' => 'rca.pdf']);
    $file = VehicleDocument::find($doc['document_id'])->file_path;

    [$err] = mcpTool($token, 'vehicles_delete', ['vehicle_id' => $car->id]);
    expect($err)->toBeFalse()->and(Vehicle::find($car->id))->toBeNull();
    Storage::disk('r2')->assertMissing($file);

    $tools = collect(mcpCall($token, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($tools['vehicles_delete']['annotations']['destructiveHint'] ?? null)->toBeTrue()
        ->and($tools['vehicles_delete_record']['annotations']['destructiveHint'] ?? null)->toBeTrue();
});

it('gives every tool a title, readOnlyHint, openWorldHint and (for writes) destructiveHint, as the Claude directory asks', function () {
    [, , $token] = deleteToken(['vehicles', 'notes']);
    $tools = collect(mcpCall($token, 'tools/list')->json('result.tools'))->keyBy('name');

    foreach ($tools as $name => $tool) {
        $a = $tool['annotations'];
        expect($a['title'] ?? '')->not->toBe('', $name)
            ->and($a)->toHaveKeys(['readOnlyHint', 'openWorldHint']);
        if (! $a['readOnlyHint']) {
            expect($a)->toHaveKey('destructiveHint');
        }
    }
    expect($tools['notes_list']['annotations']['readOnlyHint'])->toBeTrue()
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
        ->and(InventoryItem::find($i['id']))->toBeNull();
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
