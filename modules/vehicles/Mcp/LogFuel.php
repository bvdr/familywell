<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class LogFuel extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_log_fuel';

    protected string $description = 'Log a fuel fill-up. The odometer moves forward to the given reading. Set add_expense=true (with a cost) to also record it in Spending, linked to the car.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'liters' => $schema->number()->required(),
            'mileage_km' => $schema->integer()->description('Odometer at the fill, in km.')->required(),
            'cost' => $schema->number()->description('Total paid, in the family\'s currency.'),
            'filled_on' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
            'full_tank' => $schema->boolean()->description('Filled to full (default true); needed for consumption figures.'),
            'station' => $schema->string(),
            'note' => $schema->string(),
            'add_expense' => $schema->boolean()->description('Also record the cost in Spending (default false, like the app).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'liters' => ['required', 'numeric', 'gt:0'],
            'mileage_km' => ['required', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'filled_on' => ['nullable', 'date'],
            'full_tank' => ['sometimes', 'boolean'],
            'station' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'add_expense' => ['sometimes', 'boolean'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        if (($data['add_expense'] ?? false) && ! VehicleData::spendingEnabled()) {
            return Response::error('Spending is not turned on for this family, so the fill cannot be recorded as an expense. Log it without add_expense.');
        }

        $entry = $v->logFuel([
            'filled_on' => $data['filled_on'] ?? now()->toDateString(),
            'liters' => $data['liters'],
            'mileage' => (int) $data['mileage_km'],
            'cost' => $data['cost'] ?? null,
            'is_full_tank' => $data['full_tank'] ?? true,
            'station' => $data['station'] ?? null,
            'note' => $data['note'] ?? null,
            'create_expense' => $data['add_expense'] ?? false,
        ], $this->user());

        return Response::json([
            'fuel_entry_id' => $entry->id,
            'expense_recorded' => ($data['add_expense'] ?? false) && ! empty($data['cost']),
            'vehicle' => VehicleData::summary($v->fresh(['maintenanceSchedules', 'documents'])),
        ]);
    }
}
