<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Vehicles\VehicleExportController;
use Modules\Vehicles\VehicleHistoryExport;

#[IsReadOnly]
class ExportHistory extends Tool
{
    protected string $module = 'vehicles';

    protected string $name = 'vehicles_export_history';

    protected string $description = 'Use this when the user wants the car\'s full history as a PDF (e.g. to sell the car). Returns a download link to a PDF of services, repairs, fuel, documents and mileage with totals per year and category. The link works for 24 hours without signing in; give it to the person.';

    protected string $title = 'Export car history PDF';

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

        return Response::structured([
            'download_url' => VehicleExportController::signedUrl($v, $request->user()?->locale ?? 'en'),
            'filename' => VehicleHistoryExport::filename($v),
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);
    }
}
