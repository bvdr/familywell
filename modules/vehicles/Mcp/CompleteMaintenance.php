<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use App\Support\Tenancy\FamilyClock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Modules\Vehicles\MaintenanceCompletion;

#[IsOpenWorld]
class CompleteMaintenance extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_complete_maintenance';

    protected string $description = 'Use this when the user says a planned service was done. Records it in the service history, resets when it is next due, moves the odometer forward, closes its reminder task, and records any cost in Spending. An invoice file (`file`, `file_url` or `file_base64`) is filed with the car\'s documents.';

    protected string $title = 'Complete car maintenance';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->description('Car id, from vehicles_list.')->required(),
            'schedule_id' => $schema->integer()->description('schedule_id from vehicles_get.')->required(),
            'performed_on' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
            'mileage_km' => $schema->integer()->description('Odometer when it was done, in km (default: the current reading).'),
            'cost' => $schema->number()->description('Total paid, in the family\'s currency.'),
            'provider' => $schema->string()->description('Garage / who did it.'),
            'notes' => $schema->string()->description('Free-text notes.'),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'schedule_id' => ['required', 'integer'],
            'performed_on' => ['nullable', 'date'],
            'mileage_km' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'provider' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        $schedule = $v?->maintenanceSchedules()->find($data['schedule_id']);
        if (! $schedule) {
            return $this->notFound('Maintenance schedule');
        }

        $file = IncomingFile::from($request); // validated (type, size) before anything is written

        // The same completion path as the app's "Mark as completed" form and a checked-off reminder task.
        $history = MaintenanceCompletion::complete($v, $schedule, [
            'performed_at' => $data['performed_on'] ?? FamilyClock::today(),
            'mileage' => (int) ($data['mileage_km'] ?? $v->current_mileage),
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $this->user());

        // A one-off planned job is closed by completing it (no next due).
        $st = $schedule->exists ? $schedule->fresh()->statusFor((int) $v->fresh()->current_mileage) : ['nextDueMileage' => null, 'nextDueDate' => null];

        // Service records have no attachments of their own (not in the app either); an invoice for a service
        // lives with the car's documents as a `service_invoice`, which is where the app files one too.
        $document = null;
        if ($file) {
            $document = $v->documents()->create([
                'type' => 'service_invoice',
                'title' => "{$schedule->name} — {$history->performed_at->toDateString()}",
                'created_by' => $this->user()->id,
            ]);
            $document->attachFile($file);
        }

        return Response::structured([
            'completed' => $schedule->name,
            'history_id' => $history->id,
            'invoice_document_id' => $document?->id,
            'next_due_km' => $st['nextDueMileage'],
            'next_due_date' => $st['nextDueDate'],
            'one_off_closed' => ! $schedule->exists,
            'url' => VehicleData::url($schedule->exists ? "/m/vehicles/{$v->id}/maintenance/{$schedule->id}" : "/m/vehicles/{$v->id}/maintenance"),
        ]);
    }
}
