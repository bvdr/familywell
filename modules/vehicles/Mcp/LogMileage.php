<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class LogMileage extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_log_mileage';

    protected string $description = 'Record a new odometer reading in km. A reading below the current one is refused unless correction=true (for fixing a wrong value).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'mileage_km' => $schema->integer()->description('Odometer reading in kilometres.')->required(),
            'correction' => $schema->boolean()->description('true only to overwrite a wrong, higher reading with a lower one.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'mileage_km' => ['required', 'integer', 'min:0'],
            'correction' => ['sometimes', 'boolean'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }

        $v->recordMileage((int) $data['mileage_km'], $this->user(), (bool) ($data['correction'] ?? false));

        return Response::json(VehicleData::summary($v->fresh(['maintenanceSchedules', 'documents'])));
    }
}
