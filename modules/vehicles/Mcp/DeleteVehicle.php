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

    protected string $name = 'vehicles_delete_vehicle';

    protected string $description = 'Use this when the user wants a whole car removed, with everything recorded for it (mileage, services, repairs, fuel, documents and their files). It goes to the trash and can be restored for 30 days with vehicles_restore. Its expenses stay in Spending.';

    protected string $title = 'Delete car';

    public function schema(JsonSchema $schema): array
    {
        return ['vehicle_id' => $schema->integer()->description('Car id, from vehicles_list.')->required()];
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
