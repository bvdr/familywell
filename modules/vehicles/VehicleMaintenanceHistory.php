<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One performed-maintenance record: what was done, when, at what odometer, and (optionally) its cost and
 * provider. Written by "mark as completed"; `name` is snapshotted from the schedule at completion time so
 * the record stays truthful if the schedule is later renamed or deleted (its schedule_id then goes null,
 * but the row — the car's real service history — survives). cost/provider feed the P3 Spending link.
 */
class VehicleMaintenanceHistory extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_maintenance_history';

    protected $fillable = [
        'vehicle_id', 'schedule_id', 'name', 'performed_at', 'mileage',
        'cost', 'provider', 'service_provider_id', 'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'mileage' => 'integer',
        'cost' => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceSchedule::class, 'schedule_id');
    }

    /** The linked service provider (V1.1), when this service points at one instead of free text. */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'service_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The garage/provider name to display: the linked provider's name when set, else the free-text
     * fallback (quick entry / pre-V1.1 rows). Reads the `serviceProvider` relation, so eager-load it where
     * many rows render to avoid N+1.
     */
    public function providerName(): ?string
    {
        return $this->serviceProvider?->name ?? $this->provider;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'scheduleId' => $this->schedule_id,
            'name' => $this->name,
            'performedAt' => optional($this->performed_at)->toDateString(),
            'mileage' => $this->mileage,
            'cost' => $this->cost,
            // Resolved display name (linked provider wins over free text).
            'provider' => $this->providerName(),
            'serviceProviderId' => $this->service_provider_id,
            'notes' => $this->notes,
        ];
    }
}
