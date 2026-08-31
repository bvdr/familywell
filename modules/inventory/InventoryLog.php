<?php

namespace Modules\Inventory;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in an item's history: who changed the quantity, by how much, and why. Written
 * on every quantity change (added in P2) and read by the overview's "Recently updated" feed and the
 * item detail's history. `delta` is the signed change; `type` labels it (added/consumed/adjusted/
 * purchased).
 */
class InventoryLog extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_log';

    protected $fillable = ['inventory_item_id', 'delta', 'type', 'user_id', 'family_id'];

    protected $casts = ['delta' => 'float'];

    // The reasons a quantity changed. Kept in code (not a DB enum) for the same reason as UNITS.
    public const TYPES = ['added', 'consumed', 'adjusted', 'purchased'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
