<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class LogMileage extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_log_mileage';

    protected string $description = 'Record an odometer reading in km. Without a date it is a reading for today: it must not be below the current one unless correction=true (for fixing a wrong value). With a past date (e.g. from an old invoice) it is added to the history at that date, must fit between the readings around it, and does not change the current odometer.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'mileage_km' => $schema->integer()->description('Odometer reading in kilometres.')->required(),
            'correction' => $schema->boolean()->description('true only to overwrite a wrong, higher reading with a lower one.'),
            'date' => $schema->string()->format('date')->description('When the reading was taken, YYYY-MM-DD (default: now).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'mileage_km' => ['required', 'integer', 'min:0'],
            'correction' => ['sometimes', 'boolean'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }

        // A date-only reading for a past day is stamped at noon, so it sorts inside that day; today = now.
        $at = ! empty($data['date']) && ! Carbon::parse($data['date'])->isToday() ? Carbon::parse($data['date'])->setTime(12, 0) : null;
        $v->recordMileage((int) $data['mileage_km'], $this->user(), (bool) ($data['correction'] ?? false), $at);

        return Response::json(VehicleData::summary($v->fresh(['maintenanceSchedules', 'documents'])));
    }
}
