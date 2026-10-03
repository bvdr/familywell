<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\FuelStats;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleMaintenanceSchedule;
use Modules\Vehicles\VehicleOverview;

#[IsReadOnly]
class GetVehicle extends Tool
{
    protected string $module = 'vehicles';

    protected string $name = 'vehicles_get';

    protected ?string $app = 'vehicle';

    protected string $description = 'One car in full: details, maintenance schedules (recurring, and one-off planned jobs) with what is due (by km and date), documents and their expiry, fuel consumption stats, and recent services/repairs. Distances in km.';

    public function schema(JsonSchema $schema): array
    {
        return ['vehicle_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $v = VehicleData::vehicle($request->get('vehicle_id'));
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        $v->load(['maintenanceSchedules', 'documents', 'maintenanceHistory', 'repairs', 'fuelEntries', 'owner', 'primaryDriver']);
        $km = (int) $v->current_mileage;
        $fuel = FuelStats::forEntries($v->fuelEntries);

        return Response::json(VehicleData::summary($v) + [
            'version' => $v->version,
            'transmission' => $v->transmission,
            'vin' => $v->vin,
            'color' => $v->color,
            'owner' => $v->owner?->name,
            'primary_driver' => $v->primaryDriver?->name,
            'insurance_company' => $v->insurance_company,
            'notes' => $v->notes,
            'display_unit' => VehicleData::displayUnit(),
            'maintenance' => $v->maintenanceSchedules->where('active', true)->map(fn (VehicleMaintenanceSchedule $s) => VehicleData::maintenancePayload($s, $km))->values(),
            'documents' => $v->documents->sortBy(fn ($d) => $d->expires_on?->timestamp ?? PHP_INT_MAX)->map(fn (VehicleDocument $d) => [
                'document_id' => $d->id,
                'type' => $d->type,
                'has_file' => $d->file_path !== null,
                'title' => $d->title,
                'expires_on' => $d->expires_on?->toDateString(),
                'expiring_soon' => $d->isExpiring(),
            ])->values(),
            'fuel' => $fuel['entryCount'] > 0 ? [
                'fills' => $fuel['entryCount'],
                'avg_consumption_l_per_100km' => $fuel['avgConsumption'],
                'latest_consumption_l_per_100km' => $fuel['latestConsumption'],
                'cost_per_km' => $fuel['costPerKm'],
            ] : null,
            'recent' => VehicleOverview::recent($v),
        ]);
    }
}
