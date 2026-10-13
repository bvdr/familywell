<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteRecord extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_delete_record';

    protected string $description = 'Move one record of a car to the trash: a mileage reading (e.g. a duplicate), a fuel fill, a repair (with its attachments), a document (with its file), or a maintenance schedule (its past services stay in the history). Ids come from vehicles_history ("mileage-123" = mileage 123) and vehicles_get (document_id, schedule_id). Deleting the newest mileage reading sets the odometer back to the previous reading. Deleted records go to the trash, restorable for 30 days with vehicles_restore.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['mileage', 'fuel', 'repair', 'document', 'maintenance_schedule'])->required(),
            'id' => $schema->integer()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'type' => ['required', 'in:mileage,fuel,repair,document,maintenance_schedule'],
            'id' => ['required', 'integer'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        $record = match ($data['type']) {
            'mileage' => $v?->mileageLogs()->find($data['id']),
            'fuel' => $v?->fuelEntries()->find($data['id']),
            'repair' => $v?->repairs()->find($data['id']),
            'document' => $v?->documents()->find($data['id']),
            'maintenance_schedule' => $v?->maintenanceSchedules()->find($data['id']),
        };
        if (! $record) {
            return $this->notFound(str_replace('_', ' ', ucfirst($data['type'])));
        }

        // Same paths as the app's delete buttons (to the 30-day trash; files are freed at purge); mileage is
        // the one record the app has no delete for, with its own odometer rule (VehicleMileageLog::deleteReading).
        match ($data['type']) {
            'mileage' => $record->deleteReading(),
            'repair' => $record->deleteWithFiles(),
            'document' => $record->deleteWithFile(),
            default => $record->delete(), // fuel fill; schedule (its past services stay in the history)
        };

        return Response::structured([
            'deleted' => true,
            'type' => $data['type'],
            'id' => (int) $data['id'],
            'vehicle' => VehicleData::summary($v->fresh(['maintenanceSchedules', 'documents'])),
        ]);
    }
}
