<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only odometer reading: the absolute mileage, who recorded it and when. Written on every
 * change to a vehicle's `current_mileage` (including the opening reading at creation), so the history
 * and the odometer can never drift apart. `recorded_at` is the reading's timestamp (defaults to now).
 */
class VehicleMileageLog extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_mileage_log';

    protected $fillable = ['vehicle_id', 'mileage', 'recorded_by', 'recorded_at', 'family_id'];

    protected $casts = [
        'mileage' => 'integer',
        'recorded_at' => 'datetime',
    ];

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
