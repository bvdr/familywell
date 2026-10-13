<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteVehicle extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_delete';

    protected string $description = 'Move a whole car to the trash with everything recorded for it (mileage, services, repairs, fuel, documents and their files), restorable for 30 days with vehicles_restore. Its expenses stay in Spending.';

    public function schema(JsonSchema $schema): array
    {
        return ['vehicle_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = VehicleData::vehicle($request->get('vehicle_id'));
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        $name = VehicleData::summary($v)['name'];
        $v->deleteWithFiles(); // same path as the app's delete

        return Response::structured(['deleted' => true, 'vehicle' => $name]);
    }
}
