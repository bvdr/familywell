<?php

namespace Modules\Shopping;

use App\Models\User;
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

    /**
     * The list a "just add it to Shopping" action should drop an item into: the user's most recent
     * non-archived list, or a family-visible "My List" minted on first use so there is always a home.
     * Shared by the Shopping UI (adding from a shop) and the Inventory replenishment loop, so both agree
     * on where surfaced items land — the single "existing default list" the loop reuses rather than
     * standing up a parallel one.
     */
    public static function defaultFor(User $user): self
    {
        $list = static::whereNull('archived_at')->where('created_by', $user->id)->latest('id')->first();
        if ($list) {
            return $list;
        }

        $list = static::create(['name' => 'My List', 'created_by' => $user->id]);
        $list->shares()->firstOrCreate(['user_id' => $user->id], ['role' => 'owner']);
        $list->setVisibility('family');

        return $list;
    }
}
