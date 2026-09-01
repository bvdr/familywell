<?php

namespace Modules\Vehicles;

use App\Models\User;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

/**
 * The Documents -> Tasks bridge (P4). Turns an expiring document into ONE renewal task in the existing
 * Tasks module (never a parallel to-do system) and keeps the two in sync idempotently: a document records
 * the task it opened in `renewal_task_id`, so it never spawns a second while the first is still open.
 * Completing (or deleting) that task self-heals the link on the next check, letting a later expiry cycle —
 * e.g. next year's insurance — fire a fresh renewal task. Mirrors MaintenanceTasks exactly.
 */
class DocumentRenewalTasks
{
    /** The auto-provisioned per-family list every renewal task lands in. */
    private const LIST_NAME = 'Vehicle documents';

    /**
     * Whether this document already has a live renewal task — the idempotency guard. True only when
     * `renewal_task_id` points at a task that still exists and isn't done. A dangling link (task deleted or
     * checked off out-of-band) is cleared so the document can fire again.
     */
    public static function hasOpenTask(VehicleDocument $document): bool
    {
        if ($document->renewal_task_id === null) {
            return false;
        }

        $task = Task::find($document->renewal_task_id); // family-scoped resolve
        if (! $task || $task->done) {
            $document->update(['renewal_task_id' => null]); // self-heal a dangling link

            return false;
        }

        return true;
    }

    /**
     * Create the renewal task for an expiring document, unless one is already open (idempotent — safe to
     * call from the daily sweep and from the manual "Create renewal task" button). Returns the task, or the
     * existing open task when there's nothing to do. Dated to the expiry, assigned to the car's owner (then
     * primary driver).
     */
    public static function ensureForExpiring(VehicleDocument $document, Vehicle $vehicle, User $actor): Task
    {
        if (self::hasOpenTask($document)) {
            return $document->renewalTask; // already handled this cycle
        }

        $list = self::listFor((int) $vehicle->family_id, $actor);
        $label = trim($document->title) !== '' ? $document->title : $document->type;

        $task = $list->tasks()->create([
            'title' => trim("Renew {$label} — {$vehicle->make} {$vehicle->model}"),
            // A readable link back to the car + document, mirroring the maintenance task note style.
            'note' => "🚗 {$vehicle->make} {$vehicle->model} · ".self::documentLine($document),
            'due_date' => optional($document->expires_on)->toDateString(),
            'assigned_to' => $vehicle->owner_id ?? $vehicle->primary_driver_id,
            'position' => (int) $list->tasks()->whereNull('parent_id')->max('position') + 1,
            'created_by' => $actor->id,
        ]);

        $document->update(['renewal_task_id' => $task->id]);

        return $task;
    }

    /** "Insurance expires 2026-10-01" / "Insurance" — the human line for the task note. */
    private static function documentLine(VehicleDocument $document): string
    {
        $type = $document->type;
        if ($document->expires_on) {
            return "{$type} expires {$document->expires_on->toDateString()}";
        }

        return $type;
    }

    /**
     * Find-or-create the family's "Vehicle documents" task list, provisioning an owner share on first
     * creation (like the Tasks controller does). Family-visibility, so every member sees the renewal.
     */
    private static function listFor(int $familyId, User $actor): TaskList
    {
        $list = TaskList::firstOrCreate(
            ['name' => self::LIST_NAME, 'family_id' => $familyId],
            ['color' => 'green', 'icon' => 'file-text', 'created_by' => $actor->id],
        );

        if ($list->wasRecentlyCreated) {
            $list->shares()->firstOrCreate(['user_id' => $actor->id], ['role' => 'owner']);
        }

        return $list;
    }
}
