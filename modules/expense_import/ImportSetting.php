<?php

namespace Modules\ExpenseImport;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Model;

// The family's Expense import switches + defaults (one row, created on first read). Everything is off until
// an admin turns it on in the module's settings page.
class ImportSetting extends Model
{
    use BelongsToFamily;

    protected $table = 'expense_import_settings';

    protected $fillable = ['family_id', 'statements_enabled', 'shortcut_enabled', 'default_event_id', 'default_payer_key', 'auto_save'];

    protected $casts = ['statements_enabled' => 'boolean', 'shortcut_enabled' => 'boolean', 'auto_save' => 'boolean'];

    protected $attributes = ['statements_enabled' => false, 'shortcut_enabled' => false, 'auto_save' => false];

    public static function current(?int $familyId = null): self
    {
        $familyId ??= (int) CurrentFamily::id();

        return static::withoutGlobalScope('family')->firstOrCreate(['family_id' => $familyId]);
    }
}
