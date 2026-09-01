<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An unplanned repair (P4) — SEPARATE from the recurring maintenance schedules of P2/P3. A repair is a
 * one-off event that already happened: what was fixed, when, at what odometer, what it cost and where. It
 * forms the car's permanent history and never recurs, so unlike a maintenance schedule it has no interval
 * and no "next due" — it's a record, not a rule. A repair with a cost mirrors into Spending exactly like a
 * maintenance completion (via VehicleExpenses), and can carry attachments (invoice / photo) on R2.
 */
class Repair extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_repairs';

    protected $fillable = [
        'vehicle_id', 'title', 'occurred_on', 'mileage', 'cost', 'provider', 'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'mileage' => 'integer',
        'cost' => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RepairAttachment::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The wire shape every screen renders a repair from. camelCase for the client. Attachment URLs are
     * auth-gated (built by the controller), so they're passed in rather than derived here.
     *
     * @param  array<int, array{id:int, name:?string, url:string}>  $attachments
     * @return array<string, mixed>
     */
    public function toPayload(array $attachments = []): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'title' => $this->title,
            'occurredOn' => optional($this->occurred_on)->toDateString(),
            'mileage' => $this->mileage,
            'cost' => $this->cost,
            'provider' => $this->provider,
            'notes' => $this->notes,
            'attachments' => $attachments,
            'createdBy' => $this->created_by,
        ];
    }
}
