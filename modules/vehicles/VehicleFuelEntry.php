<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One fuel fill-up (V1.1) — litres, optional cost, the odometer at the fill and whether the tank was
 * filled to full. Litres are ALWAYS stored metric (like distance in km); the family's unit_system only
 * changes how they're displayed (litres vs gallons, L/100km vs mpg — see VehicleUnits / lib/vehicles.ts).
 *
 * `is_full_tank` is the key to consumption: L/100km is measured between consecutive FULL-tank fills, so a
 * partial fill still records its litres but doesn't itself close a measurement segment (see FuelStats).
 */
class VehicleFuelEntry extends Model
{
    use BelongsToFamily;

    // 30-day trash (VehiclesTrash). Its mirrored Spend stays either way; the expense link's fuel_entry_id
    // only nulls out (FK) once trash:purge erases the fill.
    use SoftDeletes;

    protected $table = 'vehicles_fuel_entries';

    protected $fillable = [
        'vehicle_id', 'filled_on', 'liters', 'cost', 'mileage', 'is_full_tank',
        'station', 'note', 'created_by', 'family_id',
    ];

    protected $casts = [
        'filled_on' => 'date',
        'liters' => 'float',
        'cost' => 'float',
        'mileage' => 'integer',
        'is_full_tank' => 'boolean',
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
            'filledOn' => optional($this->filled_on)->toDateString(),
            'liters' => $this->liters,
            'cost' => $this->cost,
            'mileage' => $this->mileage,
            'isFullTank' => $this->is_full_tank,
            'station' => $this->station,
            'note' => $this->note,
            'createdBy' => $this->created_by,
        ];
    }
}
