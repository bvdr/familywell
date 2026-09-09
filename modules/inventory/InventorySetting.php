<?php

namespace Modules\Inventory;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * The family's inventory preferences: default unit system, whether the daily check auto-adds short
 * stock to Shopping, and which conditions may raise a notification. One row per family (family_id is
 * unique), created on demand — mirrors Modules\Spending\SpendSetting exactly, so the two modules share
 * one mental model for "module settings".
 *
 * These are FAMILY-level toggles. They compose with the per-USER push mute
 * (users.notification_prefs / User::wantsPush): the family toggle decides whether an alert is generated
 * at all; the user pref decides whether a given member's device gets the push for it.
 */
class InventorySetting extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_settings';

    protected $fillable = [
        'unit_system',
        'auto_add_low', 'auto_add_empty', 'shopping_list_id',
        'notify_low', 'notify_empty', 'notify_expired', 'notify_stale',
        'family_id',
    ];

    protected $casts = [
        'auto_add_low' => 'boolean',
        'auto_add_empty' => 'boolean',
        'notify_low' => 'boolean',
        'notify_empty' => 'boolean',
        'notify_expired' => 'boolean',
        'notify_stale' => 'boolean',
    ];

    // Model-level defaults, mirroring the migration's column defaults. These make a freshly
    // firstOrCreate()'d row carry the calm "everything on" values in-memory too (the DB defaults only
    // populate on insert and don't backfill the returned instance), so reads never see a null toggle.
    protected $attributes = [
        'unit_system' => 'metric',
        'auto_add_low' => true,
        'auto_add_empty' => true,
        'notify_low' => true,
        'notify_empty' => true,
        'notify_expired' => true,
        'notify_stale' => true,
    ];

    // The measurement systems offered in Settings. No imperial units exist in InventoryItem::UNITS yet, so
    // today this is a declared default rather than a converter — kept as a small closed set for validation.
    public const UNIT_SYSTEMS = ['metric', 'imperial'];

    /**
     * The family's settings row, created with defaults on first access. withoutGlobalScopes so it also
     * resolves from the scheduled command (which runs outside a family HTTP request); family_id is passed
     * explicitly. The DB column defaults supply the "everything on" baseline, so a brand-new row is already
     * the calm default.
     */
    public static function current(?int $familyId = null): self
    {
        $familyId ??= (int) CurrentFamily::id();

        return static::withoutGlobalScopes()->firstOrCreate(['family_id' => $familyId]);
    }

    /**
     * Whether a given alert condition (low|empty|expired|stale) is enabled for the family. Unknown
     * conditions default to on, matching the "opt-in by default" spirit of the per-user prefs.
     */
    public function notifies(string $condition): bool
    {
        return match ($condition) {
            'low' => $this->notify_low,
            'empty' => $this->notify_empty,
            'expired' => $this->notify_expired,
            'stale' => $this->notify_stale,
            default => true,
        };
    }

    /**
     * The wire shape the Settings screen renders from (camelCase for the client).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'unitSystem' => $this->unit_system,
            'autoAddLow' => $this->auto_add_low,
            'autoAddEmpty' => $this->auto_add_empty,
            'notifyLow' => $this->notify_low,
            'notifyEmpty' => $this->notify_empty,
            'notifyExpired' => $this->notify_expired,
            'notifyStale' => $this->notify_stale,
        ];
    }
}
