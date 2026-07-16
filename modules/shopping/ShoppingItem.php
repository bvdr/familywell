<?php

namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShoppingItem extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_items';

    protected $fillable = ['name', 'quantity', 'bought', 'visibility', 'created_by'];

    protected $casts = ['bought' => 'boolean'];

    // Shared items are visible to everyone in the family; private items only to their creator.
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by', $userId));
    }
}
