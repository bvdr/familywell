<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Translations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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

    // 30-day trash (VehiclesTrash, trash:purge). Its records stay put while it is trashed: every reader goes
    // through the car (or skips a record whose car is gone), so they vanish with it and come back on restore.
    use SoftDeletes;

    protected $table = 'vehicles_vehicles';

    protected $fillable = [
        'make', 'model', 'year', 'version', 'fuel_type', 'transmission', 'vin', 'registration_number',
        'color', 'purchase_date', 'purchase_price', 'owner_id', 'primary_driver_id', 'current_mileage',
        'milestone_notified_km',
        'photo_path', 'license_plate', 'insurance_company', 'road_tax_status', 'inspection_status',
        'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'year' => 'integer',
        'purchase_date' => 'date',
        'purchase_price' => 'float',
        'current_mileage' => 'integer',
        'milestone_notified_km' => 'integer',
    ];

    // Fuel + transmission code lists. Kept here (not a DB enum) so adding an option is a one-line code
    // change and validation reads from one list; stored as plain strings, so an unknown value still
    // renders via the client's fallback label. Labels are localised client-side (vehicles.fuel_<key>).
    public const FUEL_TYPES = ['petrol', 'diesel', 'hybrid', 'electric', 'lpg', 'cng', 'other'];

    public const TRANSMISSIONS = ['manual', 'automatic', 'other'];

    /**
     * Create a car the way every path does (the add form, the Add sheet's "Just say it"): an opening odometer reading
     * so the history and current_mileage agree from day one (the overview never shows an empty log next to a non-zero
     * odometer). $data is already validated.
     */
    public static function createFor(User $user, array $data): self
    {
        $vehicle = self::create(['current_mileage' => (int) ($data['current_mileage'] ?? 0), 'created_by' => $user->id] + $data);
        VehicleMileageLog::create([
            'vehicle_id' => $vehicle->id,
            'mileage' => $vehicle->current_mileage,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);

        return $vehicle;
    }

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

    /** This vehicle's unplanned repairs (P4), newest first — the permanent history. */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class)->latest('occurred_on')->latest('id');
    }

    /** This vehicle's documents (P4). Expiring ones sort first, then never-expiring, then by title. */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class)->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('title');
    }

    /** Links to Notes-module notes attached to this vehicle (P4). */
    public function noteLinks(): HasMany
    {
        return $this->hasMany(VehicleNoteLink::class);
    }

    /** This vehicle's fuel fill-ups (V1.1), newest first. */
    public function fuelEntries(): HasMany
    {
        return $this->hasMany(VehicleFuelEntry::class)->latest('filled_on')->latest('id');
    }

    /** This vehicle's tyre sets (V1.1): the mounted set first, then by season, then newest. */
    public function tireSets(): HasMany
    {
        return $this->hasMany(VehicleTireSet::class)->orderByDesc('is_mounted')->orderBy('season')->latest('id');
    }

    /** This vehicle's parts requested/bought via Shopping (V1.1), newest first. */
    public function partRequests(): HasMany
    {
        return $this->hasMany(VehiclePartRequest::class)->latest('id');
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
     * Set the odometer to a new reading and log it. Readings only move forward unless the person says it is a
     * correction (a lower typo fix is authoritative); a backwards reading otherwise fails validation. Shared
     * by the Overview mileage form and the MCP tool.
     */
    public function recordMileage(int $km, User $actor, bool $correction = false, ?Carbon $at = null): void
    {
        $at ??= now();
        // A reading dated before today is HISTORY (e.g. read off an old invoice): logged at its date, it must
        // fit between the logged readings around it AND not exceed the current odometer (which is the reading
        // as of now, even when its log row is older), and it never moves the current odometer.
        if ($at->lt(today())) {
            $min = $this->mileageLogs()->where('recorded_at', '<=', $at)->max('mileage');
            $later = $this->mileageLogs()->where('recorded_at', '>', $at)->min('mileage');
            $max = min($later ?? PHP_INT_MAX, (int) $this->current_mileage);
            if (($min !== null && $km < $min) || $km > $max) {
                throw ValidationException::withMessages([
                    'mileage' => strtr(Translations::get('vehicles.mileage_out_of_order'), [':min' => (string) ($min ?? 0), ':max' => (string) $max]),
                ]);
            }
            $this->logMileage($km, $actor->id, $at);

            return;
        }

        if ($km < (int) $this->current_mileage && ! $correction) {
            throw ValidationException::withMessages([
                // The app's strings live in lang/{locale}/vehicles.json (App\Support\Translations), not Laravel's
                // PHP lang files, so __() would return the bare key here.
                'mileage' => strtr(Translations::get('vehicles.mileage_backwards'), [':current' => (string) $this->current_mileage]),
            ]);
        }
        $this->update(['current_mileage' => $km]);
        $this->logMileage($km, $actor->id, $at);
    }

    /** Bump the odometer only when $km is ahead of it (a fill/repair reading), logging it once; never rewinds. */
    public function bumpMileage(int $km, User $actor): void
    {
        if ($km > (int) $this->current_mileage) {
            $this->update(['current_mileage' => $km]);
            $this->logMileage($km, $actor->id);
        }
    }

    private function logMileage(int $km, ?int $userId, ?Carbon $at = null): void
    {
        VehicleMileageLog::create(['vehicle_id' => $this->id, 'mileage' => $km, 'recorded_by' => $userId, 'recorded_at' => $at ?? now()]);
    }

    /**
     * Log a fuel fill: the entry, the odometer (forward only, a back-dated fill never rewinds it), and when
     * asked + costed, a Spending record tagged with the fill. Shared by the Fuel form and the MCP tool.
     *
     * @param  array{filled_on:string, liters:float|string, mileage:int, cost?:?float, is_full_tank?:bool, station?:?string, note?:?string, create_expense?:bool}  $data
     */
    public function logFuel(array $data, User $actor): VehicleFuelEntry
    {
        $entry = $this->fuelEntries()->create([
            'filled_on' => $data['filled_on'],
            'liters' => $data['liters'],
            'cost' => $data['cost'] ?? null,
            'mileage' => $data['mileage'],
            'is_full_tank' => $data['is_full_tank'] ?? true,
            'station' => $data['station'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $actor->id,
        ]);
        $this->bumpMileage((int) $entry->mileage, $actor);

        if (($data['create_expense'] ?? false) && ! empty($data['cost'])) {
            // "Fuel — {station}" or "Fuel — {make} {model}"; tagged with the fill so the Timeline shows it once.
            $where = $entry->station ?: trim("{$this->make} {$this->model}");
            VehicleExpenses::record($this, $actor, (float) $data['cost'], trim('Fuel — '.$where), $data['filled_on'], null, null, $entry->id);
        }

        return $entry;
    }

    /**
     * Log a repair, and when asked + costed, a Spending record tagged with it (opt-in, unlike a service
     * completion). Attachments are the web form's business. Shared by the Repairs form and the MCP tool.
     *
     * @param  array{title:string, occurred_on:string, mileage?:?int, cost?:?float, provider?:?string, service_provider_id?:?int, notes?:?string, create_expense?:bool}  $data
     */
    public function logRepair(array $data, User $actor): Repair
    {
        $repair = $this->repairs()->create([
            'title' => $data['title'],
            'occurred_on' => $data['occurred_on'],
            'mileage' => $data['mileage'] ?? null,
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,                          // free-text fallback
            'service_provider_id' => $data['service_provider_id'] ?? null,    // preferred link (V1.1)
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        if (($data['create_expense'] ?? false) && ! empty($data['cost'])) {
            VehicleExpenses::record($this, $actor, (float) $data['cost'], trim($data['title']), $data['occurred_on'], null, $repair->id);
        }

        return $repair;
    }

    /**
     * Move the car (with everything recorded for it) to the 30-day trash. Its files stay in storage so a
     * restore gets them back; booted() frees them when trash:purge erases the car. Web delete + MCP.
     */
    public function deleteWithFiles(): void
    {
        $this->delete();
    }

    protected static function booted(): void
    {
        // Gone for good: child rows cascade in the DB, but their storage files would not, so free the photo,
        // every repair attachment and every document file first (trashed records included: the DB cascade
        // erases them too, without their own forceDeleting firing).
        static::forceDeleting(function (self $v) {
            StoredImage::delete($v->photo_path);
            foreach ($v->repairs()->withTrashed()->with('attachments')->get() as $repair) {
                $repair->attachments->each(fn ($a) => StoredImage::delete($a->path));
            }
            $v->documents()->withTrashed()->pluck('file_path')->each(fn ($p) => StoredImage::delete($p));
        });
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
