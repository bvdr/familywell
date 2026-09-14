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

        return static::withoutGlobalScopes()->firstOrCreate(['family_id' => $familyId]);
    }

    /** The family's chosen default currency, or null when unset (caller falls back). */
    public static function defaultCurrency(?int $familyId = null): ?string
    {
        return static::current($familyId)->default_currency;
    }
}
