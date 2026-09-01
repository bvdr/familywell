<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tasks\Task;

/**
 * One tyre set for a vehicle (V1.1) — its own lifecycle object, not a maintenance schedule. A car typically
 * has a summer set and a winter set; exactly one is mounted at any time (`is_mounted`), the swap being the
 * central action. `installed_on` / `installed_mileage` record when the set was last put on the car (stamped
 * by the mount action), which is what the Vehicle Timeline surfaces as a "Changed to … tyres" event.
 *
 * `open_task_id` links the seasonal "Change to … tyres" reminder in the Tasks module, so the reminder is
 * idempotent (never two open tasks for the same set) — the same pattern the maintenance schedules use.
 */
class VehicleTireSet extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_tire_sets';

    // The season code list. Kept here (not a DB enum) so adding one is a one-line change; stored as a plain
    // string, so an unknown value still renders via the client's fallback label (vehicles.tire_season_<key>).
    public const SEASONS = ['summer', 'winter', 'all_season'];

    protected $fillable = [
        'vehicle_id', 'season', 'brand', 'size', 'installed_on', 'installed_mileage',
        'tread_depth_mm', 'storage_location', 'is_mounted', 'notes', 'open_task_id',
        'created_by', 'family_id',
    ];

    protected $casts = [
        'installed_on' => 'date',
        'installed_mileage' => 'integer',
        'tread_depth_mm' => 'float',
        'is_mounted' => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The open Tasks reminder this set spawned (if any) — used to keep the seasonal task idempotent. */
    public function openTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'open_task_id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'season' => $this->season,
            'brand' => $this->brand,
            'size' => $this->size,
            'installedOn' => optional($this->installed_on)->toDateString(),
            'installedMileage' => $this->installed_mileage,
            'treadDepthMm' => $this->tread_depth_mm,
            'storageLocation' => $this->storage_location,
            'isMounted' => $this->is_mounted,
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
        ];
    }
}
