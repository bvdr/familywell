<?php

namespace Modules\Vehicles\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Console\Command;
use Modules\Notifications\Notify;
use Modules\Vehicles\MaintenanceStatus;
use Modules\Vehicles\MaintenanceTasks;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleMaintenanceSchedule;

/**
 * The daily maintenance sweep. For every family with the Vehicles module (and Tasks, since it creates
 * tasks) enabled, it turns each active schedule that is DUE or OVERDUE into exactly one Tasks task, and
 * notifies the assignee. Idempotent: a schedule that already has an open linked task is skipped, so a
 * same-day (or any) re-run never doubles up.
 *
 * Runs OUTSIDE an HTTP request, so it walks families itself and sets CurrentFamily per family (the
 * BelongsToFamily scope, the family list/event provisioning and Notify all read it). Scheduled ->daily()
 * from VehiclesServiceProvider; also runnable by hand (`php artisan vehicles:check`) for testing.
 */
class CheckVehicles extends Command
{
    protected $signature = 'vehicles:check';

    protected $description = 'Create Tasks for due/overdue vehicle maintenance and notify the assignee (daily).';

    public function handle(ModuleRegistry $modules): int
    {
        $prior = CurrentFamily::id(); // restore afterwards so a long-running worker isn't left scoped

        // Only families that actually own vehicles are worth walking (unscoped: we're outside a family
        // request). Small set — one query, then per-family work.
        $familyIds = Vehicle::withoutGlobalScopes()->select('family_id')->distinct()->pluck('family_id');

        $created = 0;

        try {
            foreach ($familyIds as $familyId) {
                $familyId = (int) $familyId;
                // Tasks are the whole point of the sweep, so both modules must be on for the family.
                if (! $modules->isEnabled('vehicles', $familyId) || ! $modules->isEnabled('tasks', $familyId)) {
                    continue;
                }

                CurrentFamily::set($familyId); // scope every read/write below to this family
                $family = Family::find($familyId);
                if (! $family) {
                    continue;
                }

                $actor = $this->actorFor($family);
                if (! $actor) {
                    continue; // no member to attribute the task/list to
                }

                $created += $this->sweepFamily($actor);
            }
        } finally {
            // Leave tenancy exactly as we found it (null-safe: forget when there was no prior scope).
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Vehicles check: {$created} maintenance task(s) created.");

        return self::SUCCESS;
    }

    /** Create tasks for the current family's due/overdue active schedules. Returns how many were created. */
    private function sweepFamily(User $actor): int
    {
        $created = 0;

        // Eager-load the vehicle so status + assignment don't N+1 per schedule.
        $schedules = VehicleMaintenanceSchedule::query()->where('active', true)->with('vehicle')->get();

        foreach ($schedules as $schedule) {
            $vehicle = $schedule->vehicle;
            if (! $vehicle) {
                continue;
            }

            $status = $schedule->statusFor((int) $vehicle->current_mileage);
            if (! MaintenanceStatus::needsAttention($status['status'])) {
                continue; // good / due-soon — nothing to raise yet
            }
            if (MaintenanceTasks::hasOpenTask($schedule)) {
                continue; // already has a live task — idempotent skip
            }

            $task = MaintenanceTasks::ensureForDue($schedule, $vehicle, $actor);
            $created++;

            // Nudge whoever it landed on (assignee), else the whole family. Not the actor's own action, so
            // no exclusion — the sweep has no human author.
            $recipients = $task->assigned_to
                ? User::whereKey($task->assigned_to)->get()
                : Family::find($vehicle->family_id)?->members ?? collect();
            Notify::push(
                $recipients,
                'vehicles',
                null,
                'Maintenance due',
                "{$schedule->name} is due on {$vehicle->make} {$vehicle->model}.",
                "/m/vehicles/{$vehicle->id}/maintenance/{$schedule->id}",
            );
        }

        return $created;
    }

    /** A concrete actor for the created list/tasks (created_by). Prefer the owner, else any admin, else any member. */
    private function actorFor(Family $family): ?User
    {
        return User::find($family->owner_id)
            ?? $family->members()->wherePivot('role', 'admin')->first()
            ?? $family->members()->first();
    }
}
