<?php

namespace Modules\Vehicles;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Tasks\Task;

/**
 * The one place a maintenance schedule gets "completed", shared by both directions of the P3 round-trip:
 *   - the Vehicles UI "Mark as completed" form (VehicleMaintenanceController::complete), and
 *   - a linked Tasks task being checked off (the task.saved hook in VehiclesServiceProvider).
 *
 * Completing a schedule: append a performed-maintenance history row, advance the schedule's baseline (so
 * the next due km/date recompute), bump+log the odometer when the reading moved it forward, mirror the
 * cost into Spending, and close+detach the linked task so the schedule can fire fresh next cycle. Kept in
 * one method so both entry points stay in lock-step — there is no second, subtly-different completion path.
 */
class MaintenanceCompletion
{
    /**
     * @param  array{performed_at:string, mileage:int, cost?:float|null, provider?:?string, service_provider_id?:?int, notes?:?string}  $data
     */
    public static function complete(Vehicle $vehicle, VehicleMaintenanceSchedule $schedule, array $data, User $actor): VehicleMaintenanceHistory
    {
        $mileage = (int) $data['mileage'];
        $cost = isset($data['cost']) && $data['cost'] !== null ? (float) $data['cost'] : null;

        $history = $vehicle->maintenanceHistory()->create([
            'schedule_id' => $schedule->id,
            'name' => $schedule->name,   // snapshot: the record stays truthful if the schedule changes later
            'performed_at' => $data['performed_at'],
            'mileage' => $mileage,
            'cost' => $cost,
            'provider' => $data['provider'] ?? null,                          // free-text fallback
            'service_provider_id' => $data['service_provider_id'] ?? null,    // preferred link (V1.1)
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        // Advance the baseline — the next due km/date recompute from this performance.
        $schedule->update([
            'last_performed_at' => $data['performed_at'],
            'last_performed_mileage' => $mileage,
        ]);

        // A completion at/above the current reading is also the latest odometer value; keep the two in step
        // and logged (never silently, and never backwards for a back-dated, lower-mileage record).
        if ($mileage > (int) $vehicle->current_mileage) {
            $vehicle->update(['current_mileage' => $mileage]);
            VehicleMileageLog::create([
                'vehicle_id' => $vehicle->id,
                'mileage' => $mileage,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);
        }

        // Mirror the cost into Spending, linked to this vehicle + this performance (no-op when there's no
        // cost or Spending is disabled for the family).
        if ($cost !== null && $cost > 0) {
            VehicleExpenses::record(
                $vehicle,
                $actor,
                $cost,
                trim("{$schedule->name} — {$vehicle->make} {$vehicle->model}"),
                $data['performed_at'],
                $history->id,
            );
        }

        // Close the round-trip: check off + detach the task this schedule had open, so a new one can fire
        // next cycle. Clearing the link first means nothing can mistake the (now handled) schedule for one
        // still awaiting a task. Marking the task done here does NOT re-enter the task.saved hook (that
        // fires only from the Tasks controller), so there's no recursion.
        if ($schedule->open_task_id !== null) {
            $taskId = $schedule->open_task_id;
            $schedule->update(['open_task_id' => null]);
            Task::where('id', $taskId)->update(['done' => true]);
        }

        return $history;
    }

    /**
     * Complete a schedule straight from its checked-off task: defaults the reading to the car's current
     * odometer and the date to today, with no cost (the quick "I did it" path from the Tasks screen). The
     * rich capture — cost, provider, notes — lives on the Vehicles "Mark as completed" form instead.
     */
    public static function fromTask(VehicleMaintenanceSchedule $schedule, User $actor): VehicleMaintenanceHistory
    {
        $vehicle = $schedule->vehicle;

        return self::complete($vehicle, $schedule, [
            'performed_at' => Carbon::today()->toDateString(),
            'mileage' => (int) $vehicle->current_mileage,
            'cost' => null,
        ], $actor);
    }
}
