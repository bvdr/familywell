<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A family vehicle — the object at the centre of the module. Phase 1 owns identity + the first-class
 * odometer (`current_mileage`, mirrored into vehicles_mileage_log on every change). Its "status" and
 * "next service" (the coloured dot / km-remaining on the card) are NOT stored: they are DERIVED, as of
 * P2, by rolling up the vehicle's maintenance schedules through MaintenanceStatus (see
 * maintenanceSummary()). Computing on read keeps the dot correct as the odometer and today's date move,
 * with no stored status to fall stale.
 */
class Vehicle extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_vehicles';

    protected $fillable = [
        'make', 'model', 'year', 'version', 'fuel_type', 'transmission', 'vin', 'registration_number',
        'color', 'purchase_date', 'purchase_price', 'owner_id', 'primary_driver_id', 'current_mileage',
        'photo_path', 'license_plate', 'insurance_company', 'road_tax_status', 'inspection_status',
        'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'year' => 'integer',
        'purchase_date' => 'date',
        'purchase_price' => 'float',
        'current_mileage' => 'integer',
    ];

    // Fuel + transmission code lists. Kept here (not a DB enum) so adding an option is a one-line code
    // change and validation reads from one list; stored as plain strings, so an unknown value still
    // renders via the client's fallback label. Labels are localised client-side (vehicles.fuel_<key>).
    public const FUEL_TYPES = ['petrol', 'diesel', 'hybrid', 'electric', 'lpg', 'cng', 'other'];

    public const TRANSMISSIONS = ['manual', 'automatic', 'other'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function primaryDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The odometer history, newest reading first. */
    public function mileageLogs(): HasMany
    {
        return $this->hasMany(VehicleMileageLog::class)->latest('recorded_at')->latest('id');
    }

    /** This vehicle's recurring maintenance rules. */
    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceSchedule::class);
    }

    /** This vehicle's performed-maintenance records, newest first. */
    public function maintenanceHistory(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceHistory::class)->latest('performed_at')->latest('id');
    }

    /**
     * Roll this vehicle's ACTIVE maintenance schedules up into the card summary: the status dot, the
     * "next service" distance, and how many items need attention. Reads the loaded `maintenanceSchedules`
     * relation, so callers that render many vehicles (My Cars) should eager-load it to avoid N+1.
     *
     * - state: the worst schedule status mapped to the three-state card dot (good / service_due / overdue).
     * - nextServiceKm: the smallest km-remaining across schedules (the soonest by distance) — negative
     *   when a km trigger is already past due; null when no schedule has an active km trigger.
     * - attention: count of schedules that are due or overdue now (drives "N things need attention").
     *
     * @return array{state:string, nextServiceKm:?int, attention:int}
     */
    public function maintenanceSummary(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $mileage = (int) $this->current_mileage;

        $worst = MaintenanceStatus::GOOD;
        $nextServiceKm = null;
        $attention = 0;

        foreach ($this->maintenanceSchedules as $schedule) {
            if (! $schedule->active) {
                continue;
            }
            $s = $schedule->statusFor($mileage, $today);
            $worst = MaintenanceStatus::worse($worst, $s['status']);
            if ($s['remainingKm'] !== null && ($nextServiceKm === null || $s['remainingKm'] < $nextServiceKm)) {
                $nextServiceKm = $s['remainingKm'];
            }
            if (MaintenanceStatus::needsAttention($s['status'])) {
                $attention++;
            }
        }

        return [
            'state' => MaintenanceStatus::toVehicleState($worst),
            'nextServiceKm' => $nextServiceKm,
            'attention' => $attention,
        ];
    }

    /**
     * The wire shape every screen renders a vehicle from. camelCase for the client. `photoUrl` is filled
     * by the controller (it needs the route + a per-request signature), so it is not built here.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'version' => $this->version,
            'fuelType' => $this->fuel_type,
            'transmission' => $this->transmission,
            'vin' => $this->vin,
            'registrationNumber' => $this->registration_number,
            'color' => $this->color,
            'purchaseDate' => optional($this->purchase_date)->toDateString(),
            'purchasePrice' => $this->purchase_price,
            'ownerId' => $this->owner_id,
            'primaryDriverId' => $this->primary_driver_id,
            'currentMileage' => $this->current_mileage,
            'hasPhoto' => $this->photo_path !== null,
            'licensePlate' => $this->license_plate,
            'insuranceCompany' => $this->insurance_company,
            'roadTaxStatus' => $this->road_tax_status,
            'inspectionStatus' => $this->inspection_status,
            'notes' => $this->notes,
            // Derived maintenance rollup (state dot + soonest service distance + attention count). Eager-
            // load `maintenanceSchedules` where many vehicles render, so this stays O(1) queries.
            ...$this->maintenanceSummary(),
            'createdBy' => $this->created_by,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
