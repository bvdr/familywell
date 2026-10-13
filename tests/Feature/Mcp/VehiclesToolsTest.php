<?php

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Spending\Spend;
use Modules\Spending\SpendSetting;
use Modules\Vehicles\Repair;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleFuelEntry;
use Modules\Vehicles\VehicleMaintenanceHistory;
use Modules\Vehicles\VehicleMileageLog;

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

// ---- Dated mileage + files (vehicles_add_document / vehicles_attach_file / files on repairs + services) ----

const TINY_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

it('logs a past-dated reading into the history without moving the odometer', function () {
    [, , $token, $car] = carsToken();
    mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 150500]); // today

    [$err] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 149000, 'date' => now()->subMonth()->toDateString()]);
    expect($err)->toBeFalse()->and($car->fresh()->current_mileage)->toBe(150500)
        ->and(VehicleMileageLog::where('mileage', 149000)->value('recorded_at')->toDateString())->toBe(now()->subMonth()->toDateString());

    // A past reading that does not fit the readings after it is refused; so is a future date.
    [$err, $msg] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 160000, 'date' => now()->subWeek()->toDateString()]);
    expect($err)->toBeTrue()->and($msg)->toContain('between');
    expect(mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 151000, 'date' => now()->addDay()->toDateString()])[0])->toBeTrue();
});

it('adds a document with a base64 file and attaches files to repairs and documents', function () {
    Storage::fake('r2');
    [, , $token, $car] = carsToken();
    $pdf = base64_encode(TINY_PDF);

    [$err, $doc] = mcpTool($token, 'vehicles_add_document', ['vehicle_id' => $car->id, 'type' => 'insurance', 'title' => 'RCA 2027', 'expires_on' => '2027-10-01', 'file_base64' => $pdf, 'file_name' => 'rca.pdf']);
    expect($err)->toBeFalse()->and($doc['file'])->toBe('rca.pdf');
    $stored = VehicleDocument::find($doc['document_id']);
    Storage::disk('r2')->assertExists($stored->file_path);

    [, $repair] = mcpTool($token, 'vehicles_add_repair', ['vehicle_id' => $car->id, 'title' => 'Brakes', 'file_base64' => $pdf, 'file_name' => 'factura.pdf']);
    expect($repair['attachment'])->toBe('factura.pdf');
    [, $more] = mcpTool($token, 'vehicles_attach_file', ['vehicle_id' => $car->id, 'target' => 'repair', 'id' => $repair['repair_id'], 'file_base64' => $pdf, 'file_name' => 'poza.pdf']);
    expect($more['attached'])->toBe('poza.pdf')
        ->and(Repair::find($repair['repair_id'])->attachments()->count())->toBe(2);

    [, $replaced] = mcpTool($token, 'vehicles_attach_file', ['vehicle_id' => $car->id, 'target' => 'document', 'id' => $doc['document_id'], 'file_base64' => $pdf, 'file_name' => 'rca-v2.pdf']);
    expect($replaced['attached'])->toBe('rca-v2.pdf')->and($stored->fresh()->file_name)->toBe('rca-v2.pdf');
    Storage::disk('r2')->assertMissing($stored->file_path); // the old file was freed
});

it('files a service deviz as a service invoice when completing maintenance', function () {
    Storage::fake('r2');
    [, , $token, $car, $oil] = carsToken();

    [, $done] = mcpTool($token, 'vehicles_complete_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $oil->id, 'file_base64' => base64_encode(TINY_PDF), 'file_name' => 'deviz.pdf']);
    $doc = VehicleDocument::find($done['invoice_document_id']);
    expect($doc->type)->toBe('service_invoice')->and($doc->title)->toStartWith('Oil & filter')->and($doc->file_name)->toBe('deviz.pdf');
});

it('takes a file from a URL or a ChatGPT file param, and refuses unsafe or wrong files', function () {
    Storage::fake('r2');
    // A closure, so each request gets a fresh response (a shared one's body stream is read to the end once).
    Http::fake(['*' => fn () => Http::response(TINY_PDF)]);
    [, , $token, $car] = carsToken();
    $base = ['vehicle_id' => $car->id, 'type' => 'service_invoice', 'title' => 'Deviz'];

    // A literal public IP host skips DNS (the sandbox may have none); the body comes from Http::fake.
    [$err, $a] = mcpTool($token, 'vehicles_add_document', $base + ['file_url' => 'https://93.184.216.34/files/deviz.pdf']);
    expect($err)->toBeFalse()->and($a['file'])->toBe('deviz.pdf');
    [$err, $b] = mcpTool($token, 'vehicles_add_document', $base + ['file' => ['download_url' => 'https://93.184.216.34/dl?id=1', 'file_id' => 'file-1', 'file_name' => 'scan.pdf']]);
    expect($err)->toBeFalse()->and($b['file'])->toBe('scan.pdf');

    $refused = fn (array $extra) => mcpTool($token, 'vehicles_add_document', $base + $extra);
    expect($refused(['file_url' => 'http://93.184.216.34/x.pdf'])[1])->toContain('https')
        ->and($refused(['file_url' => 'https://127.0.0.1/x.pdf'])[1])->toContain('private')
        ->and($refused(['file_url' => 'https://10.0.0.5/x.pdf'])[1])->toContain('private')
        ->and($refused(['file_base64' => base64_encode('just text'), 'file_name' => 'x.txt'])[1])->toContain('Unsupported')
        ->and($refused(['file_base64' => '***', 'file_name' => 'x.pdf'])[1])->toContain('base64');
    expect(VehicleDocument::count())->toBe(3); // the two above + the fixture's RCA, nothing from refusals
});

it('marks the file parameter for ChatGPT file passing', function () {
    [, , $token] = carsToken();
    $tool = collect(mcpCall($token, 'tools/list')->json('result.tools'))->firstWhere('name', 'vehicles_add_document');
    expect($tool['_meta']['openai/fileParams'])->toBe(['file'])
        ->and($tool['inputSchema']['properties'])->toHaveKeys(['file', 'file_url', 'file_base64', 'file_name']);
});

it('accepts a past reading even when the newest logged reading is older than it', function () {
    [, , $token, $car] = carsToken(); // current 150000 km, no log rows yet

    [$err, $r] = mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 149900, 'date' => now()->subDay()->toDateString()]);
    expect($err)->toBeFalse()->and($r['current_mileage_km'])->toBe(150000);
    // But it can't be above what the odometer reads today.
    expect(mcpTool($token, 'vehicles_log_mileage', ['vehicle_id' => $car->id, 'mileage_km' => 150100, 'date' => now()->subDay()->toDateString()])[0])->toBeTrue();
});

it('plans a one-off job, shows it, and closes it on completion', function () {
    [, , $token, $car] = carsToken();

    [$err, $msg] = mcpTool($token, 'vehicles_add_maintenance', ['vehicle_id' => $car->id, 'name' => 'Brakes']);
    expect($err)->toBeTrue()->and(json_encode($msg))->toContain('interval');

    [$err, $job] = mcpTool($token, 'vehicles_add_maintenance', [
        'vehicle_id' => $car->id, 'name' => 'Brakes front + rear', 'due_km' => 152000, 'notes' => 'Quote 2,910 RON',
    ]);
    expect($err)->toBeFalse()
        ->and($job)->toMatchArray(['one_off' => true, 'due_km' => 152000, 'next_due_km' => 152000, 'km_remaining' => 2000, 'status' => 'good', 'notes' => 'Quote 2,910 RON']);

    [, $got] = mcpTool($token, 'vehicles_get', ['vehicle_id' => $car->id]);
    expect(collect($got['maintenance'])->pluck('name'))->toContain('Brakes front + rear');

    [, $done] = mcpTool($token, 'vehicles_complete_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $job['schedule_id'], 'mileage_km' => 151800]);
    expect($done)->toMatchArray(['completed' => 'Brakes front + rear', 'one_off_closed' => true, 'next_due_km' => null]);
    [, $after] = mcpTool($token, 'vehicles_get', ['vehicle_id' => $car->id]);
    expect(collect($after['maintenance'])->pluck('name'))->not->toContain('Brakes front + rear')
        ->and(VehicleMaintenanceHistory::where('name', 'Brakes front + rear')->exists())->toBeTrue();
});

it('changes a schedule: bring a service forward, pause it, keep the due rule', function () {
    [$family, $users, $token, $car, $oil] = carsToken();

    [, $early] = mcpTool($token, 'vehicles_update_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $oil->id, 'due_km' => 151000]);
    expect($early)->toMatchArray(['every_km' => 15000, 'due_km' => 151000, 'next_due_km' => 151000, 'one_off' => false]);

    // Clearing every trigger would leave nothing to be due by.
    [$err] = mcpTool($token, 'vehicles_update_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $oil->id, 'every_km' => null, 'every_months' => null, 'due_km' => null]);
    expect($err)->toBeTrue();

    [, $paused] = mcpTool($token, 'vehicles_update_maintenance', ['vehicle_id' => $car->id, 'schedule_id' => $oil->id, 'active' => false]);
    expect($paused['active'])->toBeFalse()->and($oil->fresh()->interval_km)->toBe(15000);

    $read = mcpConnect($users[0], $family->id, ['vehicles' => 'read'])['access_token'];
    [$denied, $msg] = mcpTool($read, 'vehicles_add_maintenance', ['vehicle_id' => $car->id, 'name' => 'X', 'due_km' => 1]);
    expect($denied)->toBeTrue()->and($msg)->toContain('not found');
});

it('sends the family currency with car costs so the widget can format them', function () {
    [$family, , $token, $car] = carsToken(['vehicles' => 'read']);
    SpendSetting::current($family->id)->update(['default_currency' => 'RON']);

    [, $got] = mcpTool($token, 'vehicles_get', ['vehicle_id' => $car->id]);
    [, $hist] = mcpTool($token, 'vehicles_history', ['vehicle_id' => $car->id]);

    expect($got['currency'])->toBe('RON')->and($hist['currency'])->toBe('RON')->and($hist['display_unit'])->toBe('km');
});
