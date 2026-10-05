<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class AddMaintenance extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_add_maintenance';

    protected string $description = 'Plan maintenance for a car. A one-off job (e.g. a quoted brake job "in about 2,000 km") takes due_km and/or due_on and no interval; it is closed when completed (vehicles_complete_maintenance). A recurring service takes every_km and/or every_months, plus last_done_on/last_done_km as its baseline. Distances in km. Put quote details (garage, parts, price) in notes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            ...VehicleData::maintenanceSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = VehicleData::vehicle($request->get('vehicle_id'));
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        // Same rules as the app's Add maintenance form; templates are a web-form shortcut, so type is custom.
        $fields = VehicleData::maintenanceFields($request->all(), ['type' => 'custom']);
        $schedule = $v->maintenanceSchedules()->create($fields + ['created_by' => $this->user()->id]);

        return Response::structured(VehicleData::maintenancePayload($schedule->fresh(), (int) $v->current_mileage) + [
            'url' => VehicleData::url("/m/vehicles/{$v->id}/maintenance/{$schedule->id}"),
        ]);
    }
}
