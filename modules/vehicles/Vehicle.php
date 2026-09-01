<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A family vehicle — the object at the centre of the module. Phase 1 owns identity + the first-class
 * odometer (`current_mileage`, mirrored into vehicles_mileage_log on every change). Its "status" and
 * "next service" (the coloured dot / km-remaining on the card) are NOT stored: they are placeholders in
 * P1 and become derived values once maintenance schedules land in P2 — hence statePlaceholder() below.
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

    /**
     * The card's status dot. A PLACEHOLDER in Phase 1 — there is no maintenance data yet, so every
     * healthy vehicle reads "good". P2 replaces this with a real derivation from the vehicle's
     * maintenance schedules (good / service due soon / overdue). Kept as a method (not a stored column)
     * so that switch-over is purely internal and the wire shape never changes.
     */
    public function statePlaceholder(): string
    {
        return 'good';
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
            // Placeholders until P2 maintenance schedules exist — the card renders them but they carry no
            // real data yet (status is always "good", next service is unknown).
            'state' => $this->statePlaceholder(),
            'nextServiceKm' => null,
            'createdBy' => $this->created_by,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
