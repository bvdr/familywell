<?php

namespace Modules\Inventory;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// A family-definable inventory category. Items reference a category by its `key` string (not an FK),
// so a category can be seeded, added or removed without ever orphaning an item — an unknown key still
// renders via the client's fallback icon/label. Defaults are seeded per family on first read. Mirrors
// Modules\Spending\SpendCategory so the two modules share one mental model and one client renderer.
class InventoryCategory extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_categories';

    protected $fillable = ['key', 'name', 'icon', 'color', 'is_default', 'position', 'family_id'];

    protected $casts = ['is_default' => 'boolean'];

    /**
     * The built-in categories every family starts with, in display order. Icons are kebab-case lucide
     * keys (the IconPicker's vocabulary), rendered client-side by <LucideIcon> — unlike spending's
     * PascalCase defaults, so no static bridge map is needed. The label shown to users comes from i18n
     * (inventory.category_<key>), so `name` is only the English fallback.
     *
     * @var list<array{key:string,name:string,icon:string,color:string}>
     */
    public const DEFAULTS = [
        ['key' => 'food', 'name' => 'Food', 'icon' => 'utensils', 'color' => '#B5623C'],
        ['key' => 'drinks', 'name' => 'Drinks', 'icon' => 'cup-soda', 'color' => '#3E6E8E'],
        ['key' => 'cleaning', 'name' => 'Cleaning', 'icon' => 'spray-can', 'color' => '#4F7A6B'],
        ['key' => 'bathroom', 'name' => 'Bathroom', 'icon' => 'shower-head', 'color' => '#8A6D9A'],
        ['key' => 'laundry', 'name' => 'Laundry', 'icon' => 'washing-machine', 'color' => '#5E8A8A'],
        ['key' => 'household', 'name' => 'Household', 'icon' => 'house', 'color' => '#C99A3A'],
        ['key' => 'other', 'name' => 'Other', 'icon' => 'package', 'color' => '#9A8C7A'],
    ];

    // Fallback icon + a small colour cycle for admin-created custom categories, until one is chosen via
    // the IconPicker. A kebab-case lucide key (rendered by the dynamic renderer), matching the DEFAULTS.
    public const CUSTOM_ICON = 'tag';

    public const CUSTOM_COLORS = ['#6B7A99', '#8A6D9A', '#7A9A80', '#B0894F', '#5E8A8A', '#9A6B7A'];

    /**
     * Ensure the current (or given) family has its default categories. Idempotent: uses the
     * (family_id, key) unique index via insertOrIgnore, so concurrent first-loads can't double-seed and
     * re-running never disturbs existing (incl. custom) rows. Only seeds when the family has none, so an
     * admin who deletes a default doesn't get it silently recreated on the next page load.
     */
    public static function ensureDefaults(?int $familyId = null): void
    {
        $familyId ??= (int) CurrentFamily::id();
        if (! $familyId) {
            return;
        }

        // Cheap guard: if the family already has any category rows, leave them alone.
        if (static::withoutGlobalScopes()->where('family_id', $familyId)->exists()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (self::DEFAULTS as $i => $d) {
            $rows[] = [...$d, 'family_id' => $familyId, 'is_default' => true, 'position' => $i, 'created_at' => $now, 'updated_at' => $now];
        }
        static::withoutGlobalScopes()->insertOrIgnore($rows);
    }

    /** The family's categories in display order, seeding defaults first if it has none. */
    public static function forFamily(?int $familyId = null): Collection
    {
        self::ensureDefaults($familyId);

        return static::query()->orderBy('position')->orderBy('id')->get();
    }

    /** Just the valid category keys for the family (for `in:` validation rules). */
    public static function keysFor(?int $familyId = null): array
    {
        return self::forFamily($familyId)->pluck('key')->all();
    }

    /**
     * The wire shape the client uses to render a category everywhere. The label itself is resolved
     * client-side: defaults via i18n, custom ones use `name`.
     *
     * @return array{key:string,name:string,icon:string,color:string,isDefault:bool}
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'isDefault' => $this->is_default,
        ];
    }

    /** @return array<int, array{key:string,name:string,icon:string,color:string,isDefault:bool}> */
    public static function payloadFor(?int $familyId = null): array
    {
        return self::forFamily($familyId)->map(fn (self $c) => $c->toPayload())->values()->all();
    }

    /**
     * Turn an admin-entered display name into a unique, stable slug key for the family. Falls back to a
     * generic base when a name slugifies to nothing, and de-dupes with a numeric suffix so two custom
     * categories never collide on key.
     */
    public static function makeKey(string $name, int $familyId): string
    {
        $base = Str::slug($name, '_') ?: 'category';
        $key = $base;
        $existing = static::withoutGlobalScopes()->where('family_id', $familyId)->pluck('key')->all();
        $n = 2;
        while (in_array($key, $existing, true)) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
