<?php

namespace Modules\Shopping;

use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Trash\CascadesTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Notifications\Notify;

class ShoppingItem extends Model
{
    use BelongsToFamily;

    // 30-day trash; restoring an item from a deleted list restores the list first (see CascadesTrash).
    use CascadesTrash, SoftDeletes;

    protected array $trashParents = ['list'];

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

    /**
     * Fold a typed name down to what a human would call "the same item": case-insensitive, whitespace
     * collapsed, surrounding punctuation dropped ("Milk", " milk ", "milk." all match). Used by the
     * duplicate warning on add and the paste de-dupe. Not a SQL expression on purpose (sqlite/mysql
     * disagree on unicode LOWER), so callers compare in PHP over a list's active items.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));

        return preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $name) ?? $name;
    }

    /**
     * The not-yet-bought item on $list that reads as the same thing as $name, if any. Bought items
     * don't count: buying milk again next week is not a duplicate. ponytail: loads the list's active
     * names and compares in PHP — lists are tens of rows, not thousands.
     */
    public static function activeDuplicate(ShoppingList $list, string $name): ?self
    {
        $key = static::normalizeName($name);
        if ($key === '') {
            return null;
        }

        return $list->items()->where('bought', false)->get(['id', 'name', 'quantity'])
            ->first(fn (self $i) => static::normalizeName($i->name) === $key);
    }

    /**
     * Drop repeated names within one voice/paste batch (first occurrence wins) so "milk, eggs, milk"
     * reviews as two items. Rows are the parser's ['name' => ..] arrays.
     *
     * @param  list<array{name: string}>  $items
     * @return list<array{name: string}>
     */
    public static function dedupeNames(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = static::normalizeName($item['name'] ?? '');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Mark parsed rows whose name is already on $list as an active item (`on_list` => true) so the
     * voice/paste review screen can tag them. Rows are still added if the user keeps them.
     *
     * @param  list<array{name: string}>  $items
     * @return list<array{name: string, on_list?: bool}>
     */
    public static function flagOnList(ShoppingList $list, array $items): array
    {
        $active = $list->items()->where('bought', false)->pluck('name')->map(fn ($n) => static::normalizeName($n))->flip();

        return array_map(fn ($item) => $active->has(static::normalizeName($item['name'] ?? '')) ? $item + ['on_list' => true] : $item, $items);
    }

    /**
     * Side effects of an item's bought flag having been saved (web toggle or MCP update). Shared so both
     * paths behave the same:
     *  - "Shopping done": ping everyone when the last unbought item just got checked off;
     *  - the `shopping.item.bought` hook on the not-bought -> bought edge only (Inventory restocks from it,
     *    so re-checking an already-bought item can't replenish twice).
     */
    public function settleBought(bool $wasBought, User $actor): void
    {
        if (! $this->bought) {
            return;
        }
        $list = $this->list;
        if ($list && $list->items()->where('bought', false)->doesntExist() && $list->items()->count() > 0) {
            Notify::toAccessOf($list, 'shopping', $actor->id, 'Shopping done', "{$actor->name} finished {$list->name}", "/m/shopping/lists/{$list->id}");
        }
        if (! $wasBought) {
            app(Hooks::class)->fire('shopping.item.bought', $this);
        }
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
