<?php

namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A shop the family buys from. Owns the aisle layout and powers the "Shops" view (everything you need
// at that shop across every list you can see). Items/lists reference a shop by its `name`; renaming a
// shop cascades that name across items/lists/aisles so the links stay intact.
class ShoppingShop extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_shops';

    protected $fillable = ['name', 'icon', 'color', 'position'];

    public function aisles(): HasMany
    {
        return $this->hasMany(ShoppingAisle::class, 'shop_id')->orderBy('position')->orderBy('id');
    }

    // Make sure a shop row exists for a store name (called whenever a store is typed on an item/list),
    // so the Shops list stays complete. No-op for an empty name. Family scoping via BelongsToFamily.
    public static function ensure(?string $name): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return static::firstOrCreate(['name' => $name]);
    }
}
