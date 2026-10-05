<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\VehicleTimeline;

#[IsReadOnly]
class VehicleHistory extends Tool
{
    protected string $module = 'vehicles';

    protected string $name = 'vehicles_history';

    protected ?string $app = 'vehicle';

    protected string $description = 'A car\'s history, newest first: services done, repairs, mileage readings, tyre changes, documents and expenses (costed fuel fills appear as expenses), each with date, km and cost. Use it for questions like "when did we last change the oil?".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'type' => $schema->string()->description('Only this kind of entry, one of maintenance, repair, mileage, tire, document, expense.'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = VehicleData::vehicle($request->get('vehicle_id'));
        if (! $v) {
            return $this->notFound('Vehicle');
        }

        // Same feed as the car's Timeline screen (family-scoped relations).
        $entries = collect(VehicleTimeline::forVehicle($v))
            ->when($request->get('type'), fn ($c, $type) => $c->where('type', $type))
            ->values();
        $page = $this->page($entries, $request);

        return Response::structured(['history' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
