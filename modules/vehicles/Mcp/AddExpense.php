<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Tenancy\FamilyClock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Vehicles\VehicleExpenses;

class AddExpense extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_add_expense';

    protected string $description = 'Record a car expense that is not a fill-up, service or repair (parking, tolls, car wash, insurance...). It lands in Spending, linked to the car. Needs Spending turned on.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'amount' => $schema->number()->required(),
            'note' => $schema->string()->description('What it was, e.g. "Car wash".'),
            'date' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        if (! VehicleData::spendingEnabled()) {
            return Response::error('Spending is not turned on for this family, so car expenses cannot be recorded.');
        }

        // Same path as the car Overview's "Add expense": the family's "Vehicles" spending list, linked to the car.
        $spend = VehicleExpenses::record($v, $this->user(), (float) $data['amount'],
            ($data['note'] ?? null) ?: trim("{$v->make} {$v->model}"), $data['date'] ?? FamilyClock::today());

        return Response::structured([
            'expense_id' => $spend?->id,
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'currency' => $spend?->currency,
            'url' => VehicleData::url("/m/vehicles/{$v->id}"),
        ]);
    }
}
