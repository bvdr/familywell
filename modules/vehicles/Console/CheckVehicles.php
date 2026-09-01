<?php

namespace Modules\Vehicles\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Console\Command;
use Modules\Notifications\Notify;
use Modules\Vehicles\DocumentRenewalTasks;
use Modules\Vehicles\MaintenanceStatus;
use Modules\Vehicles\MaintenanceTasks;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleDocument;
use Modules\Vehicles\VehicleMaintenanceSchedule;
use Modules\Vehicles\VehicleSetting;

/**
 * The daily vehicles sweep. For every family with the Vehicles module (and Tasks, since it creates tasks)
 * enabled, it (1) turns each active maintenance schedule that is DUE or OVERDUE into exactly one Tasks task
 * and (2) turns each document whose expiry is approaching into exactly one renewal task, notifying the
 * assignee in both cases. Idempotent: a schedule/document that already has an open linked task is skipped,
 * so a same-day (or any) re-run never doubles up.
 *
 * Runs OUTSIDE an HTTP request, so it walks families itself and sets CurrentFamily per family (the
 * BelongsToFamily scope, the family list/event provisioning and Notify all read it). Scheduled ->daily()
 * from VehiclesServiceProvider; also runnable by hand (`php artisan vehicles:check`) for testing.
 */
class CheckVehicles extends Command
{
    protected $signature = 'vehicles:check';

    protected $description = 'Create Tasks for due/overdue vehicle maintenance and notify the assignee (daily).';

    // The mileage-milestone step (spec 20): notify once per this many km crossed. A round 10k so the
    // milestone reads naturally ("passed 150,000 km").
    private const MILESTONE_KM = 10000;

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

                // The family's notification policy: each condition (maintenance / documents / milestone)
                // can be silenced. The TASK is still created (it's the actionable workflow, idempotent) —
                // only the push is gated, exactly like InventorySetting composes with the per-user mute.
                $settings = VehicleSetting::current($familyId);

                $created += $this->sweepFamily($actor, $settings);
                $created += $this->sweepDocuments($actor, $settings);
                $this->sweepMilestones($settings);
            }
        } finally {
            // Leave tenancy exactly as we found it (null-safe: forget when there was no prior scope).
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Vehicles check: {$created} task(s) created (maintenance + document renewals).");

        return self::SUCCESS;
    }

    /** Create tasks for the current family's due/overdue active schedules. Returns how many were created. */
    private function sweepFamily(User $actor, VehicleSetting $settings): int
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

            if (! $settings->notifies('maintenance')) {
                continue; // task raised, but the family has silenced maintenance pushes
            }

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

    /**
     * Create renewal tasks for the current family's documents whose expiry is approaching (within
     * VehicleDocument::EXPIRY_LEAD_DAYS). Idempotent via renewal_task_id, so a re-run never doubles up.
     * Returns how many were created.
     */
    private function sweepDocuments(User $actor, VehicleSetting $settings): int
    {
        $created = 0;

        // Only documents with an expiry can be "approaching" — pre-filter in SQL, then apply the exact
        // window in PHP (isExpiring) so the lead-time rule lives in one place (the model).
        $documents = VehicleDocument::query()->whereNotNull('expires_on')->with('vehicle')->get();

        foreach ($documents as $document) {
            $vehicle = $document->vehicle;
            if (! $vehicle || ! $document->isExpiring()) {
                continue; // no vehicle, or not within the renewal window yet
            }
            if (DocumentRenewalTasks::hasOpenTask($document)) {
                continue; // already has a live renewal task — idempotent skip
            }

            $task = DocumentRenewalTasks::ensureForExpiring($document, $vehicle, $actor);
            $created++;

            if (! $settings->notifies('documents')) {
                continue; // renewal task raised, but the family has silenced document pushes
            }

            $recipients = $task->assigned_to
                ? User::whereKey($task->assigned_to)->get()
                : Family::find($vehicle->family_id)?->members ?? collect();
            Notify::push(
                $recipients,
                'vehicles',
                null,
                'Document expiring',
                "{$document->title} for {$vehicle->make} {$vehicle->model} is expiring.",
                "/m/vehicles/{$vehicle->id}/documents",
            );
        }

        return $created;
    }

    /**
     * The OPTIONAL mileage-milestone notification (spec 20). When enabled, push once each time a car crosses
     * a new MILESTONE_KM boundary (e.g. every 10,000 km). Idempotent per period via
     * vehicles_vehicles.milestone_notified_km — we only fire past the last milestone we recorded, and update
     * it whether or not a push goes out, so toggling the setting on later never back-fills old milestones.
     */
    private function sweepMilestones(VehicleSetting $settings): void
    {
        // Off by default — do no work (and mark nothing) unless the family opted in.
        if (! $settings->notifies('milestone')) {
            return;
        }

        foreach (Vehicle::query()->get() as $vehicle) {
            $mileage = (int) $vehicle->current_mileage;
            $milestone = intdiv($mileage, self::MILESTONE_KM) * self::MILESTONE_KM;

            if ($milestone < self::MILESTONE_KM || $milestone <= (int) $vehicle->milestone_notified_km) {
                continue; // not past a new milestone yet
            }

            // Record first (idempotency), then notify — a re-run today won't double-fire.
            $vehicle->update(['milestone_notified_km' => $milestone]);

            $recipients = Family::find($vehicle->family_id)?->members ?? collect();
            Notify::push(
                $recipients,
                'vehicles',
                null,
                'Mileage milestone',
                "{$vehicle->make} {$vehicle->model} has passed ".number_format($milestone).' km.',
                "/m/vehicles/{$vehicle->id}",
            );
        }
    }

    /** A concrete actor for the created list/tasks (created_by). Prefer the owner, else any admin, else any member. */
    private function actorFor(Family $family): ?User
    {
        return User::find($family->owner_id)
            ?? $family->members()->wherePivot('role', 'admin')->first()
            ?? $family->members()->first();
    }
}
