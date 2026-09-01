<?php

namespace Modules\Vehicles;

use App\Models\User;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

/**
 * The Maintenance -> Tasks bridge. Turns a due schedule into a task in the existing Tasks module (never a
 * parallel to-do system) and keeps the two in sync idempotently: a schedule records the task it opened in
 * `open_task_id`, so it never spawns a second while the first is still open. Completing the maintenance
 * clears the link (MaintenanceCompletion), which is what lets a later cycle fire a fresh task.
 */
class MaintenanceTasks
{
    /** The auto-provisioned per-family list every maintenance task lands in. */
    private const LIST_NAME = 'Vehicle maintenance';

    /**
     * Whether this schedule already has a live task — the idempotency guard. True only when `open_task_id`
     * points at a task that still exists and isn't done. If it points at a task that was deleted or checked
     * off out-of-band, the stale link is cleared so the schedule can fire again.
     */
    public static function hasOpenTask(VehicleMaintenanceSchedule $schedule): bool
    {
        if ($schedule->open_task_id === null) {
            return false;
        }

        $task = Task::find($schedule->open_task_id); // family-scoped resolve
        if (! $task || $task->done) {
            $schedule->update(['open_task_id' => null]); // self-heal a dangling link

            return false;
        }

        return true;
    }

    /**
     * Create the linked task for a due schedule, unless one is already open (idempotent — safe to call from
     * the daily sweep and from the manual "Create task" button). Returns the task, or the existing open task
     * when there's nothing to do. Assigns it to the car's owner (then primary driver) and dates it to the
     * next time-based due date when the schedule has one.
     */
    public static function ensureForDue(VehicleMaintenanceSchedule $schedule, Vehicle $vehicle, User $actor): Task
    {
        if (self::hasOpenTask($schedule)) {
            return $schedule->openTask; // already handled this cycle
        }

        $list = self::listFor($vehicle->family_id, $actor);
        $status = $schedule->statusFor((int) $vehicle->current_mileage);

        $task = $list->tasks()->create([
            'title' => trim("{$schedule->name} — {$vehicle->make} {$vehicle->model}"),
            // The link back to the car + service, as a readable note (spec 6: "🚗 Dacia Duster · Oil change").
            'note' => "🚗 {$vehicle->make} {$vehicle->model} · {$schedule->name}",
            'due_date' => $status['nextDueDate'], // null for a km-only schedule
            'assigned_to' => $vehicle->owner_id ?? $vehicle->primary_driver_id,
            'position' => (int) $list->tasks()->whereNull('parent_id')->max('position') + 1,
            'created_by' => $actor->id,
        ]);

        $schedule->update(['open_task_id' => $task->id]);

        return $task;
    }

    /**
     * Find-or-create the family's "Vehicle maintenance" task list, provisioning an owner share on first
     * creation (like the Tasks controller does) so it's a proper, manageable list. Family-visibility by
     * default, so every member sees and can check off the car tasks.
     */
    private static function listFor(int $familyId, User $actor): TaskList
    {
        $list = TaskList::firstOrCreate(
            ['name' => self::LIST_NAME, 'family_id' => $familyId],
            ['color' => 'green', 'icon' => 'car', 'created_by' => $actor->id],
        );

        if ($list->wasRecentlyCreated) {
            $list->shares()->firstOrCreate(['user_id' => $actor->id], ['role' => 'owner']);
        }

        return $list;
    }
}
