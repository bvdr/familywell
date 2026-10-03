<?php

namespace Modules\Vehicles;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Tasks\Task;

/**
 * A maintenance rule for one vehicle: two triggers (every `interval_km` and/or every `interval_months`),
 * a last-performed baseline, and a computed due status. An explicit due point (`due_mileage` / `due_on`)
 * overrides the computed next-due; with no interval at all it is a one-off planned job. The status/next-due are NOT
 * stored — they are derived on read from MaintenanceStatus so they always track the current odometer and
 * today's date (mileage moves daily; a stored status would rot). "Mark as completed" appends a history
 * row and advances the baseline, from which the next due point recomputes.
 */
class VehicleMaintenanceSchedule extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_maintenance_schedules';

    protected $fillable = [
        'vehicle_id', 'name', 'type', 'interval_km', 'interval_months', 'due_mileage', 'due_on',
        'last_performed_at', 'last_performed_mileage', 'notes', 'active', 'open_task_id', 'created_by', 'family_id',
    ];

    protected $casts = [
        'interval_km' => 'integer',
        'interval_months' => 'integer',
        'due_mileage' => 'integer',
        'due_on' => 'date',
        'last_performed_at' => 'date',
        'last_performed_mileage' => 'integer',
        'active' => 'boolean',
        'open_task_id' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Tasks task currently open for this schedule (P3: due -> task). Null once the maintenance is
     * completed, so the next due cycle can spawn a fresh one. A plain reference, not an FK — MaintenanceTasks
     * confirms the task still exists and is open before trusting it as "already handled".
     */
    public function openTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'open_task_id');
    }

    /** A planned job done once (no interval, only a due point): completing it closes it. */
    public function isOneOff(): bool
    {
        return $this->interval_km === null && $this->interval_months === null;
    }

    /**
     * Validation shared by the web form and the MCP tools. A schedule needs something to be due by: an
     * interval (recurring) and/or an explicit due point (one-off, or an early due this time).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'max:40'],
            'interval_km' => ['nullable', 'required_without_all:interval_months,due_mileage,due_on', 'integer', 'min:1', 'max:1000000'],
            'interval_months' => ['nullable', 'required_without_all:interval_km,due_mileage,due_on', 'integer', 'min:1', 'max:600'],
            'due_mileage' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'due_on' => ['nullable', 'date'],
            'last_performed_at' => ['nullable', 'date'],
            'last_performed_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** This rule's performed-maintenance records, newest first. */
    public function history(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceHistory::class, 'schedule_id')
            ->latest('performed_at')->latest('id');
    }

    /**
     * The computed due picture for this schedule at a given odometer + date (defaults to the schedule's
     * vehicle current_mileage and today). A thin wrapper over the pure engine — the model only supplies
     * its columns; all the logic lives in MaintenanceStatus so it stays testable without a DB.
     *
     * @return array{status:string, nextDueMileage:?int, nextDueDate:?string, remainingKm:?int, remainingDays:?int, neverPerformed:bool}
     */
    public function statusFor(int $currentMileage, ?Carbon $today = null): array
    {
        return MaintenanceStatus::compute(
            $this->interval_km,
            $this->interval_months,
            $this->last_performed_mileage,
            $this->last_performed_at,
            $currentMileage,
            $today ?? Carbon::today(),
            $this->due_mileage,
            $this->due_on,
        );
    }

    /**
     * The wire shape for the schedule list / detail. Needs the vehicle's current odometer to compute the
     * live status, so it is passed in (not read off a possibly-unloaded relation).
     *
     * @return array<string, mixed>
     */
    public function toPayload(int $currentMileage, ?Carbon $today = null): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'name' => $this->name,
            'type' => $this->type,
            'intervalKm' => $this->interval_km,
            'intervalMonths' => $this->interval_months,
            'dueMileage' => $this->due_mileage,
            'dueOn' => optional($this->due_on)->toDateString(),
            'oneOff' => $this->isOneOff(),
            'lastPerformedAt' => optional($this->last_performed_at)->toDateString(),
            'lastPerformedMileage' => $this->last_performed_mileage,
            'notes' => $this->notes,
            'active' => $this->active,
            ...$this->statusFor($currentMileage, $today),
        ];
    }
}
