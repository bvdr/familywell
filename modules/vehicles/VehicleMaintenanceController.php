<?php

namespace Modules\Vehicles;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2: the maintenance schedule engine's screens — the per-vehicle Maintenance list, the add/edit
 * schedule form (template or custom), the event detail with its history, and "mark as completed" (writes
 * a history record and advances the schedule's baseline so the next due point recomputes).
 *
 * Same tenancy shape as VehiclesController: route-model binding resolves {vehicle}/{schedule} before the
 * `family` middleware sets the active tenant, so every action guards the family explicitly instead of
 * leaning on the global BelongsToFamily scope. A schedule is additionally guarded to belong to its
 * vehicle, so a mismatched pair can't be reached.
 *
 * Phase 3 wires the cross-module workflows: "mark completed" runs the shared MaintenanceCompletion (history
 * + baseline + odometer + Spending + closing the linked task), and the detail screen's "Create task" /
 * "Add expense" actions are live (MaintenanceTasks / VehicleExpenses).
 */
class VehicleMaintenanceController extends Controller
{
    /** How many past performances the event detail shows. */
    private const HISTORY_LIMIT = 50;

    /** Maintenance tab: every schedule with its live computed status, most urgent first. */
    public function index(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);
        $today = Carbon::today();
        $mileage = (int) $vehicle->current_mileage;

        // Sort by urgency (overdue → due → due_soon → good), then by the soonest trigger within a status,
        // so the list reads top-down as "deal with these first". Done in PHP because the status is derived,
        // not a column — the schedule set per vehicle is small, so this is cheap.
        $schedules = $vehicle->maintenanceSchedules()->get()
            ->map(fn (VehicleMaintenanceSchedule $s) => $s->toPayload($mileage, $today))
            ->sort(fn ($a, $b) => [-self::rank($a['status']), self::soonest($a)] <=> [-self::rank($b['status']), self::soonest($b)])
            ->values();

        return Inertia::render('vehicles/maintenance/index', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'schedules' => $schedules,
        ]);
    }

    /** Add schedule: template picker + custom, with the catalogue defaults for prefilling. */
    public function create(Request $request, Vehicle $vehicle): Response
    {
        $this->authorizeVehicle($vehicle);

        return Inertia::render('vehicles/maintenance/form', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'schedule' => null,
            // The template picker pre-fills from the family's CUSTOM default intervals when set (spec 20),
            // falling back to MaintenanceTemplate's shipped defaults per key.
            'templates' => VehicleSetting::current()->templateOptions(),
        ]);
    }

    /** Create a schedule and open its detail. */
    public function store(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeVehicle($vehicle);
        $data = $this->validateSchedule($request);
        $data['created_by'] = $request->user()->id;

        $schedule = $vehicle->maintenanceSchedules()->create($data);

        return redirect()->route('vehicles.maintenance.show', [$vehicle, $schedule]);
    }

    /** Event detail: live status, last performed, next due, and the history of past performances. */
    public function show(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): Response
    {
        $this->authorizeSchedule($vehicle, $schedule);

        $history = $schedule->history()->limit(self::HISTORY_LIMIT)->get()
            ->map(fn (VehicleMaintenanceHistory $h) => $h->toPayload())->values();

        return Inertia::render('vehicles/maintenance/detail', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'schedule' => $schedule->toPayload((int) $vehicle->current_mileage),
            'history' => $history,
            // P3: the open linked task (if any) so the detail can link to it instead of offering "Create
            // task" again, and whether Spending is on so "Add expense" only shows when it can land somewhere.
            'linkedTask' => $this->linkedTask($schedule),
            'canExpense' => app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()),
        ]);
    }

    /**
     * The schedule's currently-open maintenance task, as a small payload the detail links to — or null when
     * there is none (or a stale link, which hasOpenTask self-heals). listId lets the client deep-link to the
     * task's list in the Tasks module.
     *
     * @return array{id:int, listId:?int, title:string}|null
     */
    private function linkedTask(VehicleMaintenanceSchedule $schedule): ?array
    {
        if (! MaintenanceTasks::hasOpenTask($schedule)) {
            return null;
        }
        $task = $schedule->openTask()->first();

        return $task ? ['id' => $task->id, 'listId' => $task->task_list_id, 'title' => $task->title] : null;
    }

    /** Edit schedule: the form prefilled (templates offered for switching type/intervals). */
    public function edit(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): Response
    {
        $this->authorizeSchedule($vehicle, $schedule);

        return Inertia::render('vehicles/maintenance/form', [
            'vehicle' => $this->vehiclePayload($vehicle),
            'schedule' => $schedule->toPayload((int) $vehicle->current_mileage),
            'templates' => MaintenanceTemplate::options(),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($vehicle, $schedule);
        $schedule->update($this->validateSchedule($request));

        return back();
    }

    public function destroy(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($vehicle, $schedule);
        $schedule->delete();   // its history rows keep their record (schedule_id set null by the FK).

        return redirect()->route('vehicles.maintenance.index', $vehicle);
    }

    /**
     * Mark a schedule as completed: record a performed-maintenance history row (date, mileage, cost,
     * provider, notes) and advance the schedule's baseline to that performance, so the next due point
     * recomputes from it. If the performance mileage is at or above the current odometer it also becomes
     * the new odometer (with its own mileage-log row) — completing a service IS a fresh reading — but a
     * historical/back-dated completion at a lower mileage never rewinds the odometer.
     */
    public function complete(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($vehicle, $schedule);

        $data = $request->validate([
            'performed_at' => ['required', 'date'],
            'mileage' => ['required', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'provider' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // One shared completion path (also used by a checked-off linked task): writes history, advances the
        // baseline, bumps+logs the odometer if it moved forward, mirrors any cost into Spending, and closes
        // the linked task. See MaintenanceCompletion.
        MaintenanceCompletion::complete($vehicle, $schedule, $data, $request->user());

        return back();
    }

    /**
     * Manually create the linked Tasks task for this schedule (the event-detail "Create task" action).
     * Idempotent — if a task is already open it's a no-op, so the button and the daily sweep never collide.
     */
    public function createTask(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($vehicle, $schedule);
        abort_unless(app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id()), 404);

        MaintenanceTasks::ensureForDue($schedule, $vehicle, $request->user());

        return back();
    }

    /**
     * Record an ad-hoc expense for this car into Spending (the event-detail "Add expense" action), linked to
     * the vehicle. Not tied to a specific performance — that link is set only when a completion carries a cost.
     */
    public function addExpense(Request $request, Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($vehicle, $schedule);
        abort_unless(app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id()), 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'spent_at' => ['nullable', 'date'],
        ]);

        VehicleExpenses::record(
            $vehicle,
            $request->user(),
            (float) $data['amount'],
            $data['note'] ?: trim("{$schedule->name} — {$vehicle->make} {$vehicle->model}"),
            $data['spent_at'] ?? now()->toDateString(),
        );

        return back();
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * Shared validation for add/edit. At least one interval must be set (required_without pair): a rule
     * with neither trigger would never be computable. current_mileage / today are not inputs here — the
     * baseline is `last_performed_*` and the status is derived on read.
     *
     * @return array<string, mixed>
     */
    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'max:40'],
            'interval_km' => ['nullable', 'required_without:interval_months', 'integer', 'min:1', 'max:1000000'],
            'interval_months' => ['nullable', 'required_without:interval_km', 'integer', 'min:1', 'max:600'],
            'last_performed_at' => ['nullable', 'date'],
            'last_performed_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** The compact vehicle header shape every maintenance screen needs (title + odometer + state dot). */
    private function vehiclePayload(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'version' => $vehicle->version,
            'currentMileage' => (int) $vehicle->current_mileage,
            ...$vehicle->maintenanceSummary(),
        ];
    }

    /** Urgency rank for sorting (overdue highest). Mirrors MaintenanceStatus ordering. */
    private static function rank(string $status): int
    {
        return match ($status) {
            MaintenanceStatus::OVERDUE => 3,
            MaintenanceStatus::DUE => 2,
            MaintenanceStatus::DUE_SOON => 1,
            default => 0,
        };
    }

    /**
     * A single "how soon" ordering key within a status bucket: the nearer of the two triggers. km and
     * days aren't the same unit, but both being "smaller = sooner" is enough to keep the most pressing
     * item on top; unknown (never-performed) sinks to the bottom of its bucket.
     */
    private static function soonest(array $payload): int
    {
        $candidates = array_filter([$payload['remainingKm'], $payload['remainingDays']], fn ($v) => $v !== null);

        return $candidates === [] ? PHP_INT_MAX : (int) min($candidates);
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless((int) $vehicle->family_id === (int) CurrentFamily::id(), 404);
    }

    /** Guard the vehicle's family AND that the schedule belongs to this vehicle. */
    private function authorizeSchedule(Vehicle $vehicle, VehicleMaintenanceSchedule $schedule): void
    {
        $this->authorizeVehicle($vehicle);
        abort_unless((int) $schedule->vehicle_id === (int) $vehicle->id, 404);
    }
}
