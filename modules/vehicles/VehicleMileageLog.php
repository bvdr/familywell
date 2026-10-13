<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One append-only odometer reading: the absolute mileage, who recorded it and when. Written on every
 * change to a vehicle's `current_mileage` (including the opening reading at creation), so the history
 * and the odometer can never drift apart. `recorded_at` is the reading's timestamp (defaults to now).
 */
class VehicleMileageLog extends Model
{
    use BelongsToFamily;

    // 30-day trash (VehiclesTrash). booted() undoes deleteReading()'s odometer fallback on restore.
    use SoftDeletes;

    protected $table = 'vehicles_mileage_log';

    protected $fillable = ['vehicle_id', 'mileage', 'recorded_by', 'recorded_at', 'family_id'];

    protected $casts = [
        'mileage' => 'integer',
        'recorded_at' => 'datetime',
    ];

    /**
     * Remove one reading from the history (e.g. a duplicate). The current odometer only changes when this
     * was the newest reading AND the odometer still shows its value: it then falls back to the newest
     * remaining reading. Any other reading (or a car with no other readings) leaves the odometer alone.
     */
    public function deleteReading(): void
    {
        $vehicle = $this->vehicle;
        $newest = $vehicle->mileageLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $this->delete();

        if ($newest?->is($this) && (int) $vehicle->current_mileage === (int) $this->mileage) {
            $previous = $vehicle->mileageLogs()->orderByDesc('recorded_at')->orderByDesc('id')->value('mileage');
            if ($previous !== null) {
                $vehicle->update(['current_mileage' => (int) $previous]);
            }
        }
    }

    protected static function booted(): void
    {
        // Mirror of deleteReading(): restoring what is again the newest reading moves the odometer back up to
        // it (never down; a later correction stays).
        static::restored(function (self $log) {
            $vehicle = $log->vehicle;
            $newest = $vehicle?->mileageLogs()->orderByDesc('recorded_at')->orderByDesc('id')->first();
            if ($newest?->is($log) && (int) $vehicle->current_mileage < (int) $log->mileage) {
                $vehicle->update(['current_mileage' => (int) $log->mileage]);
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'mileage' => $this->mileage,
            'recordedBy' => $this->recorded_by,
            'recordedAt' => optional($this->recorded_at)->toIso8601String(),
        ];
    }
}
