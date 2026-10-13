<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Spending\SpendSetting;
use Modules\Vehicles\Repair;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExportController;
use Modules\Vehicles\VehicleFuelEntry;
use Modules\Vehicles\VehicleHistoryExport;
use Modules\Vehicles\VehicleMaintenanceHistory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// "Export history": the car's record as a PDF, from the History screen and via a signed MCP link.

function exportCar(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach (['vehicles', 'tasks', 'spending'] as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }
    SpendSetting::current($family->id)->update(['default_currency' => 'RON']);

    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'year' => 2019, 'current_mileage' => 152000, 'license_plate' => 'B 123 ABC', 'vin' => 'UU1HSDAG123456789']);
    VehicleMaintenanceHistory::create(['vehicle_id' => $car->id, 'name' => 'Oil change', 'performed_at' => '2025-05-10', 'mileage' => 142000, 'cost' => 450, 'provider' => 'Auto Service']);
    Repair::create(['vehicle_id' => $car->id, 'title' => 'Brake pads', 'occurred_on' => '2026-03-02', 'mileage' => 148000, 'cost' => 300.5]);
    VehicleFuelEntry::create(['vehicle_id' => $car->id, 'filled_on' => '2026-04-01', 'liters' => 40, 'cost' => 250, 'mileage' => 150000, 'is_full_tank' => true, 'station' => 'OMV']);

    return [$user, $family, $car];
}

it('groups the record by year with totals per category, per year and overall', function () {
    [, , $car] = exportCar();

    $d = VehicleHistoryExport::data($car);

    expect($d['currency'])->toBe('RON')
        ->and(array_column($d['years'], 'year'))->toBe(['2026', '2025'])
        ->and($d['years'][0]['total'])->toBe(550.5)
        ->and($d['years'][0]['groups']['fuel']['rows'][0])->toMatchArray(['title' => 'OMV', 'liters' => 40.0, 'cost' => 250.0])
        ->and($d['years'][1]['groups']['maintenance']['rows'][0])->toMatchArray(['title' => 'Oil change', 'detail' => 'Auto Service', 'km' => 142000])
        ->and($d['totals'])->toMatchArray(['maintenance' => 450.0, 'repair' => 300.5, 'fuel' => 250.0])
        ->and($d['summary']['spent'])->toBe(1000.5)
        // 1000.5 spent over 152,000 - 142,000 km on record.
        ->and($d['summary']['cost_per_km'])->toBe(1000.5 / 10000);
});

it('downloads the history as a PDF from the History screen, only for the car\'s family', function () {
    [$user, , $car] = exportCar();

    $res = actingAs($user)->withHeader('Accept-Language', 'ro-RO')->get("/m/vehicles/{$car->id}/history.pdf")->assertOk();
    expect($res->headers->get('content-type'))->toBe('application/pdf')
        ->and($res->headers->get('content-disposition'))->toContain('Dacia-Duster-B-123-ABC-history-')
        ->and(substr($res->getContent(), 0, 5))->toBe('%PDF-');

    $other = User::factory()->create();
    $otherFamily = Family::factory()->create(['owner_id' => $other->id]);
    $otherFamily->members()->attach($other, ['role' => 'admin']);
    app(ModuleRegistry::class)->enable('vehicles', $otherFamily->id);
    actingAs($other)->withSession(['active_family_id' => $otherFamily->id])->get("/m/vehicles/{$car->id}/history.pdf")->assertNotFound();
});

it('serves a signed link with no session, and refuses an unsigned or tampered one', function () {
    [, , $car] = exportCar();
    $url = VehicleExportController::signedUrl($car, 'ro');
    auth()->logout();
    CurrentFamily::forget();

    expect(substr(get($url)->assertOk()->getContent(), 0, 5))->toBe('%PDF-');
    get("/vehicles/history/{$car->id}.pdf")->assertForbidden();
    get(str_replace("/{$car->id}.pdf", '/'.($car->id + 1).'.pdf', $url))->assertForbidden();
});

it('hands the assistant a download link', function () {
    [$family, $users] = mcpFamily(['vehicles', 'tasks', 'spending']);
    CurrentFamily::set($family->id);
    $car = Vehicle::create(['make' => 'Dacia', 'model' => 'Logan', 'current_mileage' => 1000]);
    $token = mcpConnect($users[0], $family->id, ['vehicles' => 'read'])['access_token'];

    [$err, $out] = mcpTool($token, 'vehicles_export_history', ['vehicle_id' => $car->id]);

    expect($err)->toBeFalse()->and($out['download_url'])->toContain("/vehicles/history/{$car->id}.pdf")
        ->and($out['filename'])->toEndWith('.pdf');
});
