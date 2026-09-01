<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part the family wants to buy for a vehicle (V1.1) — mirrored into the Shopping module as a labelled
 * shopping item. `shopping_item_id` links the open Shopping row (the idempotency pointer + what the
 * shopping.item.bought listener matches on); `status` moves requested -> bought when that row is purchased.
 * `maintenance_schedule_id` / `repair_id` record the service the part is for, when it was requested from one.
 */
class VehiclePartRequest extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_part_requests';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_BOUGHT = 'bought';

    protected $fillable = [
        'vehicle_id', 'name', 'estimated_cost', 'status', 'bought_on', 'shopping_item_id',
        'maintenance_schedule_id', 'repair_id', 'created_by', 'family_id',
    ];

    protected $casts = [
        'estimated_cost' => 'float',
        'bought_on' => 'date',
        'shopping_item_id' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'name' => $this->name,
            'estimatedCost' => $this->estimated_cost,
            'status' => $this->status,
            'boughtOn' => optional($this->bought_on)->toDateString(),
            'onShoppingList' => $this->shopping_item_id !== null,
            'maintenanceScheduleId' => $this->maintenance_schedule_id,
            'repairId' => $this->repair_id,
            'createdBy' => $this->created_by,
        ];
    }
}
