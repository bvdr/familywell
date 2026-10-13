<?php

namespace Modules\Vehicles\Mcp;

use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Validator;
use Modules\Vehicles\Vehicle;
use Modules\Vehicles\VehicleExpenses;
use Modules\Vehicles\VehicleMaintenanceSchedule;
use Modules\Vehicles\VehicleOverview;
use Modules\Vehicles\VehicleSetting;

// Shared lookups + payload shapes for the Vehicles MCP tools. Cars are family-wide (no per-car sharing:
// every member sees and edits the same cars), so the BelongsToFamily scope under CurrentFamily IS the
// access rule. Distances are always kilometres in and out (miles are only a display preference).
class VehicleData
{
    public static function vehicle(mixed $id): ?Vehicle
    {
        return Vehicle::find((int) $id);
    }

    public static function summary(Vehicle $v): array
    {
        $status = VehicleOverview::status($v);

        return [
            'id' => $v->id,
            'name' => trim("{$v->make} {$v->model}".($v->year ? " ({$v->year})" : '')),
            'make' => $v->make,
            'model' => $v->model,
            'year' => $v->year,
            'license_plate' => $v->license_plate ?: $v->registration_number,
            'fuel_type' => $v->fuel_type,
            'current_mileage_km' => (int) $v->current_mileage,
            'status' => $status['overall'], // good | attention
            'maintenance_needing_attention' => $status['maintenanceAttention'],
            'documents_expiring' => $status['documentsExpiring'],
            'url' => self::url("/m/vehicles/{$v->id}"),
        ];
    }

    /** One maintenance schedule as the assistant sees it (vehicles_get and the schedule write tools). */
    public static function maintenancePayload(VehicleMaintenanceSchedule $s, int $km): array
    {
        $st = $s->statusFor($km);

        return [
            'schedule_id' => $s->id,
            'name' => $s->name,
            'one_off' => $s->isOneOff(),
            'status' => $st['status'], // good | due_soon | due | overdue
            'every_km' => $s->interval_km,
            'every_months' => $s->interval_months,
            'due_km' => $s->due_mileage, // explicit due point (one-off, or early this time)
            'due_on' => $s->due_on?->toDateString(),
            'last_done_on' => $s->last_performed_at?->toDateString(),
            'last_done_km' => $s->last_performed_mileage,
            'next_due_km' => $st['nextDueMileage'],
            'next_due_date' => $st['nextDueDate'],
            'km_remaining' => $st['remainingKm'],
            'days_remaining' => $st['remainingDays'],
            'notes' => $s->notes,
            'active' => $s->active,
        ];
    }

    /** Tool parameters for adding/changing a schedule (shared by the two tools). */
    public static function maintenanceSchema($schema): array
    {
        return [
            'name' => $schema->string()->description('What it is, e.g. "Brakes front + rear", "Oil change".'),
            'every_km' => $schema->integer()->nullable()->description('Repeats every N km. Omit/null for a one-off job.'),
            'every_months' => $schema->integer()->nullable()->description('Repeats every N months. Omit/null for a one-off job.'),
            'due_km' => $schema->integer()->nullable()->description('Due at this odometer reading (km). For a one-off job, or to bring a recurring one forward this time.'),
            'due_on' => $schema->string()->format('date')->nullable()->description('Due on this date, YYYY-MM-DD. Same use as due_km.'),
            'last_done_on' => $schema->string()->format('date')->nullable()->description('When a recurring service was last done (its baseline).'),
            'last_done_km' => $schema->integer()->nullable()->description('Odometer when it was last done.'),
            'notes' => $schema->string()->nullable()->description('Details, e.g. the quote: garage, parts, price.'),
        ];
    }

    /**
     * Tool arguments -> validated schedule columns, with the web form's rules (VehicleMaintenanceSchedule::
     * rules). $base is the current schedule for an update, so the "something to be due by" rule is checked
     * on the result, not on the partial change.
     */
    public static function maintenanceFields(array $args, array $base = []): array
    {
        $map = [
            'name' => 'name', 'every_km' => 'interval_km', 'every_months' => 'interval_months', 'due_km' => 'due_mileage',
            'due_on' => 'due_on', 'last_done_on' => 'last_performed_at', 'last_done_km' => 'last_performed_mileage',
            'notes' => 'notes', 'active' => 'active',
        ];
        $fields = $base;
        foreach ($map as $arg => $col) {
            if (array_key_exists($arg, $args)) {
                $fields[$col] = $args[$arg];
            }
        }

        return Validator::make($fields, VehicleMaintenanceSchedule::rules())->validate();
    }

    /** The family's display unit, so the assistant can phrase distances the way the family reads them. */
    public static function displayUnit(): string
    {
        return VehicleSetting::current()->unit_system === 'imperial' ? 'miles' : 'km';
    }

    /** Currency of every cost in the vehicle payloads, so the widget can show "408,53 lei", not "408.53". */
    public static function currency(): string
    {
        return VehicleExpenses::currency((int) CurrentFamily::id());
    }

    public static function spendingEnabled(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('spending', (int) CurrentFamily::id());
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
