<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Vehicles\VehiclesTrash;

class Restore extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_restore';

    protected string $description = 'Use this when the user wants a deleted car (with everything recorded for it) or one of its records back. Restoring the newest mileage reading moves the odometer back up to it. Takes type and id from vehicles_list_trash.';

    protected string $title = 'Restore car or record';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('The type from vehicles_list_trash.')->enum(self::types())->required(),
            'id' => $schema->integer()->description('The id from vehicles_list_trash.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', self::types())], 'id' => ['required', 'integer']]);
        $path = VehiclesTrash::restore($this->user(), $data['type'], (int) $data['id']);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good; a record of a deleted car comes back by restoring the car).');
        }

        return Response::structured(['restored' => true, 'type' => $data['type'], 'id' => (int) $data['id'], 'url' => $this->url($path)]);
    }

    /** @return list<string> */
    private static function types(): array
    {
        return ['vehicle', ...array_keys(VehiclesTrash::RECORDS)];
    }
}
