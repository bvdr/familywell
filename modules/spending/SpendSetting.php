<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

// Per-family spending-module settings (one row per family): the default currency (pre-selects the
// create-event form, analytics default display currency) and the pointer to the family's "Others" list
// (where an expense added without choosing a list goes — see SpendEvent::others()).
class SpendSetting extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_settings';

    protected $fillable = ['default_currency', 'others_event_id'];

    /** The family's own settings row, created on demand (defaults blank). */
    public static function current(?int $familyId = null): self
    {
        $familyId ??= (int) CurrentFamily::id();

        // family_id is set explicitly, not through firstOrCreate's attributes: it isn't fillable, so those
        // dropped it and BelongsToFamily's creating hook filed the row under the ACTIVE family (or none),
        // which broke current($otherFamilyId), e.g. setting up a family that isn't active yet (onboarding).
        $setting = static::withoutGlobalScopes()->firstOrNew(['family_id' => $familyId]);
        if (! $setting->exists) {
            $setting->forceFill(['family_id' => $familyId])->save();
        }

        return $setting;
    }

    /** The family's chosen default currency, or null when unset (caller falls back). */
    public static function defaultCurrency(?int $familyId = null): ?string
    {
        return static::current($familyId)->default_currency;
    }
}
