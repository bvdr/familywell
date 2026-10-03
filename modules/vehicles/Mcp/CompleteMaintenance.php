<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Vehicles\MaintenanceCompletion;

class CompleteMaintenance extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_complete_maintenance';

    protected string $description = 'Mark a maintenance schedule (from vehicles_get) as done: records it in the service history, resets when it is next due, moves the odometer forward, closes its reminder task, and records any cost in Spending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'schedule_id' => $schema->integer()->description('schedule_id from vehicles_get.')->required(),
            'performed_on' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
            'mileage_km' => $schema->integer()->description('Odometer when it was done, in km (default: the current reading).'),
            'cost' => $schema->number(),
            'provider' => $schema->string()->description('Garage / who did it.'),
            'notes' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'schedule_id' => ['required', 'integer'],
            'performed_on' => ['nullable', 'date'],
            'mileage_km' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'provider' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        $schedule = $v?->maintenanceSchedules()->find($data['schedule_id']);
        if (! $schedule) {
            return $this->notFound('Maintenance schedule');
        }

        // The same completion path as the app's "Mark as completed" form and a checked-off reminder task.
        $history = MaintenanceCompletion::complete($v, $schedule, [
            'performed_at' => $data['performed_on'] ?? now()->toDateString(),
            'mileage' => (int) ($data['mileage_km'] ?? $v->current_mileage),
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $this->user());

        $st = $schedule->fresh()->statusFor((int) $v->fresh()->current_mileage);

        return Response::json([
            'completed' => $schedule->name,
            'history_id' => $history->id,
            'next_due_km' => $st['nextDueMileage'],
            'next_due_date' => $st['nextDueDate'],
            'url' => VehicleData::url("/m/vehicles/{$v->id}/maintenance/{$schedule->id}"),
        ]);
    }
}
