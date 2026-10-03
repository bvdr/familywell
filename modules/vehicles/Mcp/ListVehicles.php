<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\Vehicle;

#[IsReadOnly]
class ListVehicles extends Tool
{
    protected string $module = 'vehicles';

    protected string $name = 'vehicles_list';

    protected ?string $app = 'vehicle';

    protected string $description = 'The family\'s cars with their odometer (km) and whether anything needs attention (maintenance due, documents expiring).';

    public function handle(Request $request): Response
    {
        $cars = Vehicle::query()->with(['maintenanceSchedules', 'documents'])->orderBy('make')->orderBy('model')->get();

        return Response::json([
            'vehicles' => $cars->map(fn (Vehicle $v) => VehicleData::summary($v))->values(),
            'display_unit' => VehicleData::displayUnit(),
        ]);
    }
}
