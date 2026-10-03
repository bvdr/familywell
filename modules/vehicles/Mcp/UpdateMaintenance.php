<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateMaintenance extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_update_maintenance';

    protected string $description = 'Change a maintenance schedule (schedule_id from vehicles_get): name, intervals, due point, baseline, notes, or active=false to pause it. Only the fields you pass change; pass null to clear one. Turning a recurring service into a one-off = clear every_km and every_months and set due_km/due_on.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'schedule_id' => $schema->integer()->required(),
            ...VehicleData::maintenanceSchema($schema),
            'active' => $schema->boolean()->description('false = paused: kept, but not counted in the car\'s status.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $v = VehicleData::vehicle($request->get('vehicle_id'));
        $schedule = $v?->maintenanceSchedules()->find($request->get('schedule_id'));
        if (! $schedule) {
            return $this->notFound('Maintenance schedule');
        }
        $base = $schedule->only(['name', 'type', 'interval_km', 'interval_months', 'due_mileage', 'last_performed_mileage', 'notes', 'active']) + [
            'due_on' => $schedule->due_on?->toDateString(),
            'last_performed_at' => $schedule->last_performed_at?->toDateString(),
        ];
        $schedule->update(VehicleData::maintenanceFields($request->all(), $base));

        return Response::json(VehicleData::maintenancePayload($schedule->fresh(), (int) $v->current_mileage) + [
            'url' => VehicleData::url("/m/vehicles/{$v->id}/maintenance/{$schedule->id}"),
        ]);
    }
}
