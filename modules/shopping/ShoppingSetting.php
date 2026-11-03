<?php

namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

// The family's Shopping settings (design S1d): what new lists start with (default shop, sort, bought items), the
// helpers (AI aisle sort, duplicate warning, screen on in shop mode) and clean-up (bought items leave a list
// clean_after_days after they were ticked, into Recently deleted; a list can override, S2c). One row per family.
class ShoppingSetting extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_settings';

    protected $fillable = ['default_shop', 'sort', 'bought', 'ai_sort', 'warn_duplicates', 'keep_awake', 'auto_clean', 'clean_after_days'];

    protected $casts = ['ai_sort' => 'boolean', 'warn_duplicates' => 'boolean', 'keep_awake' => 'boolean', 'auto_clean' => 'boolean', 'clean_after_days' => 'integer'];

    // Same as the columns' defaults, so a family with no row yet reads the same as a fresh one.
    protected $attributes = ['sort' => 'aisle', 'bought' => 'sink', 'ai_sort' => true, 'warn_duplicates' => true, 'keep_awake' => true, 'auto_clean' => true, 'clean_after_days' => 7];

    /** The family's row, created on first need (family_id set explicitly, like SpendSetting::current). */
    public static function current(?int $familyId = null): self
    {
        $familyId ??= (int) CurrentFamily::id();
        $setting = static::withoutGlobalScopes()->firstOrNew(['family_id' => $familyId]);
        if (! $setting->exists) {
            $setting->forceFill(['family_id' => $familyId])->save();
        }

        return $setting;
    }
}
