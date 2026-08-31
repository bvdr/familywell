<?php

namespace Modules\Inventory;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (item, condition) recording the last day the daily `inventory:check` notified about it.
 * This is the whole idempotency story for the scheduled command: it notifies only when
 * last_notified_on is not today, so a same-day re-run never double-fires, while a condition still true
 * tomorrow nudges again (idempotent per condition/day). When a condition clears, its row is pruned, so a
 * condition that returns later notifies fresh rather than being suppressed by a stale record.
 */
class InventoryAlert extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_alerts';

    protected $fillable = ['inventory_item_id', 'condition', 'last_notified_on', 'family_id'];

    protected $casts = ['last_notified_on' => 'date'];
}
