<?php

namespace Modules\Vehicles;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * The family's Vehicles preferences (P5): the display unit system, the notification condition toggles,
 * and the family's custom default maintenance-template intervals. One row per family (family_id unique),
 * created on demand — mirrors Modules\Inventory\InventorySetting exactly, so the two modules share one
 * mental model for "module settings".
 *
 * These are FAMILY-level toggles. The notification ones compose with the per-USER push mute
 * (users.notification_prefs / User::wantsPush): the family toggle decides whether an alert is generated
 * at all; the user pref decides whether a given member's device gets the push for it.
 *
 * Units: vehicles deliberately does NOT invent a parallel unit framework. Distance is always stored in km;
 * `unit_system` is the single switch that decides whether it is DISPLAYED in km (metric) or miles
 * (imperial) — see VehicleUnits.
 */
class VehicleSetting extends Model
{
    use BelongsToFamily;

    protected $table = 'vehicles_settings';

    protected $fillable = [
        'unit_system',
        'notify_maintenance', 'notify_documents', 'notify_mileage_milestone',
        'template_intervals',
        'family_id',
    ];

    protected $casts = [
        'notify_maintenance' => 'boolean',
        'notify_documents' => 'boolean',
        'notify_mileage_milestone' => 'boolean',
        'template_intervals' => 'array',
    ];

    // Model-level defaults mirroring the migration's column defaults, so a freshly firstOrCreate()'d row
    // carries the calm values in-memory too (DB defaults only populate on insert, not the returned model).
    protected $attributes = [
        'unit_system' => 'metric',
        'notify_maintenance' => true,
        'notify_documents' => true,
        'notify_mileage_milestone' => false,
    ];

    // The display unit systems offered in Settings. Kept a small closed set for validation; the actual
    // km<->miles conversion lives in VehicleUnits.
    public const UNIT_SYSTEMS = ['metric', 'imperial'];

    /**
     * The family's settings row, created with defaults on first access. withoutGlobalScopes so it also
     * resolves from the scheduled command (which runs outside a family HTTP request); family_id is passed
     * explicitly.
     */
    public static function current(?int $familyId = null): self
    {
        $familyId ??= (int) CurrentFamily::id();

        return static::withoutGlobalScopes()->firstOrCreate(['family_id' => $familyId]);
    }

    /**
     * Whether a given notification condition (maintenance|documents|milestone) is enabled for the family.
     * Unknown conditions default to on, matching the opt-in-by-default spirit of the per-user prefs.
     */
    public function notifies(string $condition): bool
    {
        return match ($condition) {
            'maintenance' => $this->notify_maintenance,
            'documents' => $this->notify_documents,
            'milestone' => $this->notify_mileage_milestone,
            default => true,
        };
    }

    /**
     * The effective default interval for a maintenance template key: the family override if present, else
     * the shipped MaintenanceTemplate default. Always returns both keys (km/months, either nullable).
     *
     * @return array{km:?int, months:?int}
     */
    public function templateInterval(string $key): array
    {
        $shipped = MaintenanceTemplate::TEMPLATES[$key] ?? ['km' => null, 'months' => null];
        $override = $this->template_intervals[$key] ?? null;
        if (! is_array($override)) {
            return $shipped;
        }

        // A missing/blank sub-key falls through to the shipped default; array_key_exists lets an explicit
        // null override mean "not tracked by this trigger".
        return [
            'km' => array_key_exists('km', $override) ? $this->intOrNull($override['km']) : $shipped['km'],
            'months' => array_key_exists('months', $override) ? $this->intOrNull($override['months']) : $shipped['months'],
        ];
    }

    /**
     * The full template catalogue for the add-schedule picker, with family overrides applied. Same shape
     * as MaintenanceTemplate::options() so the client picker is unchanged.
     *
     * @return list<array{key:string, intervalKm:?int, intervalMonths:?int}>
     */
    public function templateOptions(): array
    {
        $out = [];
        foreach (array_keys(MaintenanceTemplate::TEMPLATES) as $key) {
            $iv = $this->templateInterval($key);
            $out[] = ['key' => $key, 'intervalKm' => $iv['km'], 'intervalMonths' => $iv['months']];
        }

        return $out;
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    /**
     * The wire shape the Settings screen renders from (camelCase for the client). Templates come back with
     * their effective (overridden-or-shipped) intervals + a flag marking which were customised.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $templates = [];
        foreach (array_keys(MaintenanceTemplate::TEMPLATES) as $key) {
            $iv = $this->templateInterval($key);
            $templates[] = [
                'key' => $key,
                'intervalKm' => $iv['km'],
                'intervalMonths' => $iv['months'],
                'customised' => isset($this->template_intervals[$key]),
            ];
        }

        return [
            'unitSystem' => $this->unit_system,
            'notifyMaintenance' => $this->notify_maintenance,
            'notifyDocuments' => $this->notify_documents,
            'notifyMileageMilestone' => $this->notify_mileage_milestone,
            'templates' => $templates,
        ];
    }
}
