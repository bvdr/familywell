<?php

namespace Modules\Birthdays;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * The family's birthday reminder default (design 10b M11): how many days ahead, and the family-local hour it
 * arrives. One row per family, created on first save; until then the column defaults (3 days, 9:00) apply, so
 * reading never writes.
 */
class BirthdaySettings extends Model
{
    use BelongsToFamily;

    protected $table = 'birthdays_settings';

    protected $fillable = ['family_id', 'remind_days', 'send_hour'];

    protected $attributes = ['remind_days' => 3, 'send_hour' => 9];

    protected $casts = ['remind_days' => 'integer', 'send_hour' => 'integer'];

    /** The current family's settings (unsaved defaults when it never changed them). */
    public static function current(): self
    {
        return self::query()->firstOrNew();
    }
}
