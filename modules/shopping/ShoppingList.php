<?php

namespace Modules\Shopping;

use App\Models\User;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Trash\CascadesTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShoppingList extends Model
{
    use BelongsToFamily;
    // shares() + visibility()/roleFor() come from here

    // Deleting a list goes to the 30-day trash and takes its items along (see CascadesTrash).
    use CascadesTrash, SoftDeletes;
    use Shareable;

    protected array $trashChildren = ['items'];

    protected $table = 'shopping_lists';

    protected $fillable = ['name', 'store', 'color', 'icon', 'archived_at', 'position', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingItem::class);
    }

    /**
     * Create a list owned by $user: the creator gets the `owner` share and the list defaults to `family`
     * visibility; a typed store becomes a real shop (Shops tab). Shared by the web UI and the MCP tools.
     */
    public static function createFor(User $user, array $attrs, ?string $visibility = null): self
    {
        $list = static::create([
            'name' => $attrs['name'],
            'store' => $attrs['store'] ?? null,
            'color' => $attrs['color'] ?? 'green',
            'icon' => $attrs['icon'] ?? 'cart',
            'created_by' => $user->id,
        ]);
        ShoppingShop::ensure($attrs['store'] ?? null);
        $list->shares()->create(['user_id' => $user->id, 'role' => 'owner']);
        if ($visibility) {
            $list->setVisibility($visibility);
        }

        return $list;
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
