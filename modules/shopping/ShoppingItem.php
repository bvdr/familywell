<?php

namespace Modules\Shopping;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingItem extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_items';

    protected $fillable = ['shopping_list_id', 'name', 'quantity', 'department', 'bought', 'note', 'assigned_to', 'due_date', 'position', 'created_by', 'store', 'alt_stores'];

    protected $casts = ['bought' => 'boolean', 'alt_stores' => 'array', 'due_date' => 'date'];

    // Mirror the column default so a freshly created model (no refresh) already reads 'other',
    // which the AisleGuesser `created` hook relies on.
    protected $attributes = ['department' => 'other'];

    protected static function booted(): void
    {
        // Every create path (quick-add, shop add, voice/paste bulk, inventory replenish, list copy)
        // funnels through here, so items left in "Other" get an AI aisle guess after the response.
        static::created(fn (self $item) => AisleGuesser::queue($item));
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
