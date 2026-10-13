<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// Deleted cars, and records deleted on their own, in Settings > Recently deleted and the MCP trash tools.
// Vehicles are family-wide (any member may edit or delete), so the family scope is the whole access rule.
// Records are only listed while their car is live: a trashed car's records never left (see Vehicle), so
// they come back by restoring the car.
class VehiclesTrash implements TrashSource
{
    /** trash type => [model, app path under /m/vehicles/{vehicle}] */
    public const RECORDS = [
        'mileage' => [VehicleMileageLog::class, '/history'],
        'fuel' => [VehicleFuelEntry::class, '/fuel'],
        'repair' => [Repair::class, '/repairs'],
        'document' => [VehicleDocument::class, '/documents'],
        'maintenance_schedule' => [VehicleMaintenanceSchedule::class, '/maintenance'],
    ];

    public static function list(User $user): Collection
    {
        $rows = Vehicle::onlyTrashed()->get()->map(fn (Vehicle $v) => Trash::row($v, 'vehicle', self::carName($v), 'Vehicles'));
        $units = VehicleSetting::current()->unit_system;
        foreach (self::RECORDS as $type => [$model]) {
            $rows = $rows->concat($model::onlyTrashed()->whereHas('vehicle')->with('vehicle')->get()
                ->map(fn (Model $r) => Trash::row($r, $type, self::title($type, $r, $units), self::carName($r->vehicle))));
        }

        return $rows;
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        if ($type === 'vehicle') {
            $car = Vehicle::onlyTrashed()->find($id);
            $car?->restore();

            return $car ? "/m/vehicles/{$car->id}" : null;
        }
        [$model, $page] = self::RECORDS[$type] ?? [null, null];
        $record = $model ? $model::onlyTrashed()->whereHas('vehicle')->find($id) : null;
        if (! $record) {
            return null;
        }
        $record->restore();

        return "/m/vehicles/{$record->vehicle_id}{$page}";
    }

    private static function carName(Vehicle $v): string
    {
        return trim("{$v->make} {$v->model}");
    }

    private static function title(string $type, Model $r, string $units): string
    {
        return match ($type) {
            'mileage' => VehicleUnits::distanceText((int) $r->mileage, $units),
            'fuel' => trim($r->filled_on->toDateString().($r->station ? " · {$r->station}" : '')),
            'maintenance_schedule' => $r->name,
            default => $r->title, // repair, document
        };
    }
}
