<?php

namespace Modules\Vehicles;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Spending\Spend;

/**
 * Joins a Spending `Spend` to the vehicle it was spent on (P3: Maintenance -> Spending). Owned by the
 * Vehicles module, so the Spending module stays unaware of vehicles — this table is the sole bridge, and
 * it is what lets Spending answer "how much on this car" (sum the linked spends' base_amount for a
 * vehicle). `maintenance_history_id` records which performed service produced the expense, when it came
 * from a completion (null for an ad-hoc "Add expense").
 */
class VehicleExpenseLink extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_expense_links';

    protected $fillable = ['vehicle_id', 'spend_id', 'maintenance_history_id', 'repair_id', 'fuel_entry_id', 'family_id'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** The repair this expense came from, when it was a repair's opt-in cost mirror (P5, else null). */
    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class, 'repair_id');
    }

    /** The fuel entry this expense came from, when it was a costed fill's mirror (V1.1, else null). */
    public function fuelEntry(): BelongsTo
    {
        return $this->belongsTo(VehicleFuelEntry::class, 'fuel_entry_id');
    }

    public function spend(): BelongsTo
    {
        return $this->belongsTo(Spend::class, 'spend_id');
    }

    public function history(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceHistory::class, 'maintenance_history_id');
    }
}
