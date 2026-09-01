<?php

namespace Modules\Vehicles;

use App\Models\User;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

/**
 * The Tyres -> Tasks bridge (seasonal reminder). Turns "remind me to change to this set" into a task in the
 * existing Tasks module, idempotently: the tyre set records the task it opened in `open_task_id`, so a second
 * identical reminder is never spawned while the first is still open. Mirrors MaintenanceTasks exactly, and
 * lands the task in the SAME "Vehicle maintenance" list (a tyre change is vehicle upkeep), so all car tasks
 * live together — firstOrCreate converges on the one list.
 */
class TireTasks
{
    /** The auto-provisioned per-family list car tasks land in (shared with MaintenanceTasks). */
    private const LIST_NAME = 'Vehicle maintenance';

    /**
     * Whether this set already has a live reminder task — the idempotency guard. True only when `open_task_id`
     * points at a task that still exists and isn't done. A deleted or checked-off task self-heals the link so
     * a fresh reminder can fire next season.
     */
    public static function hasOpenTask(VehicleTireSet $set): bool
    {
        if ($set->open_task_id === null) {
            return false;
        }

        $task = Task::find($set->open_task_id); // family-scoped resolve
        if (! $task || $task->done) {
            $set->update(['open_task_id' => null]); // self-heal a dangling link

            return false;
        }

        return true;
    }

    /**
     * Create the seasonal "Change to … tyres" reminder for a set, unless one is already open (idempotent —
     * calling twice returns the same task, never a duplicate). Assigns it to the car's owner (then primary
     * driver), and dates it today (a "do this now" nudge).
     */
    public static function remind(VehicleTireSet $set, Vehicle $vehicle, User $actor): Task
    {
        if (self::hasOpenTask($set)) {
            return $set->openTask; // already reminded — no duplicate
        }

        $list = self::listFor($vehicle->family_id, $actor);
        $seasonLabel = ucfirst(str_replace('_', ' ', $set->season));

        $task = $list->tasks()->create([
            'title' => trim("Change to {$seasonLabel} tyres — {$vehicle->make} {$vehicle->model}"),
            'note' => "🚗 {$vehicle->make} {$vehicle->model} · {$seasonLabel} tyres",
            'assigned_to' => $vehicle->owner_id ?? $vehicle->primary_driver_id,
            'position' => (int) $list->tasks()->whereNull('parent_id')->max('position') + 1,
            'created_by' => $actor->id,
        ]);

        $set->update(['open_task_id' => $task->id]);

        return $task;
    }

    /**
     * Find-or-create the family's "Vehicle maintenance" task list, provisioning an owner share on first
     * creation (like the Tasks controller does). Same list MaintenanceTasks uses, so car tasks stay together.
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
