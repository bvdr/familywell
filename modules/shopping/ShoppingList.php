<?php

namespace Modules\Shopping;

use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor() come from here

    protected $table = 'shopping_lists';

    protected $fillable = ['name', 'store', 'color', 'icon', 'archived_at', 'position', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }
}
