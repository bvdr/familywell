<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\Vehicle;

#[IsReadOnly]
class ListVehicles extends Tool
{
    protected string $module = 'vehicles';

    protected string $name = 'vehicles_list';

    protected ?string $app = 'vehicle';

    protected string $description = 'Use this when the user asks about the family\'s cars or you need a car id. Returns each car with its odometer (km) and whether anything needs attention (maintenance due, documents expiring). Read-only.';

    protected string $title = 'List cars';

    public function handle(Request $request): Response|ResponseFactory
    {
        $cars = Vehicle::query()->with(['maintenanceSchedules', 'documents'])->orderBy('make')->orderBy('model')->get();

        return Response::structured([
            'vehicles' => $cars->map(fn (Vehicle $v) => VehicleData::summary($v))->values(),
            'display_unit' => VehicleData::displayUnit(),
        ]);
    }
}
