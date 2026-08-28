<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

// Per-family spending-module settings (one row per family). Currently just the default currency, used
// to pre-select the create-event form and as the analytics default display currency.
class SpendSetting extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_settings';

    protected $fillable = ['default_currency'];

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
