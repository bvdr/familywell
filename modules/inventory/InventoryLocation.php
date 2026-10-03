<?php

namespace Modules\Inventory;

use App\Support\Tenancy\BelongsToFamily;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// A family-definable storage location (Kitchen, Pantry, Fridge, …). Same key-referenced, lazily-seeded
// shape as InventoryCategory — an item stores a location KEY, never an FK, so a location can come and go
// without orphaning items. No colour: a location is just an icon + label.
class InventoryLocation extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_locations';

    protected $fillable = ['key', 'name', 'icon', 'is_default', 'position', 'family_id'];

    protected $casts = ['is_default' => 'boolean'];

    /**
     * The built-in locations every family starts with, in display order. Icons are kebab-case lucide
     * keys; the label shown comes from i18n (inventory.location_<key>), so `name` is the English fallback.
     *
     * @var list<array{key:string,name:string,icon:string}>
     */
    public const DEFAULTS = [
        ['key' => 'kitchen', 'name' => 'Kitchen', 'icon' => 'cooking-pot'],
        ['key' => 'pantry', 'name' => 'Pantry', 'icon' => 'archive'],
        ['key' => 'fridge', 'name' => 'Fridge', 'icon' => 'refrigerator'],
        ['key' => 'freezer', 'name' => 'Freezer', 'icon' => 'snowflake'],
        ['key' => 'bathroom', 'name' => 'Bathroom', 'icon' => 'bath'],
        ['key' => 'garage', 'name' => 'Garage', 'icon' => 'warehouse'],
        ['key' => 'other', 'name' => 'Other', 'icon' => 'map-pin'],
    ];

    public const CUSTOM_ICON = 'map-pin';

    /**
     * Ensure the current (or given) family has its default locations. Idempotent; only seeds when the
     * family has none (see InventoryCategory::ensureDefaults for the reasoning).
     */
    public static function ensureDefaults(?int $familyId = null): void
    {
        $familyId ??= (int) CurrentFamily::id();
        if (! $familyId) {
            return;
        }

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

    /** The family's locations in display order, seeding defaults first if it has none. */
    public static function forFamily(?int $familyId = null): Collection
    {
        self::ensureDefaults($familyId);

        return static::query()->orderBy('position')->orderBy('id')->get();
    }

    /** Just the valid location keys for the family (for `in:` validation rules). */
    public static function keysFor(?int $familyId = null): array
    {
        return self::forFamily($familyId)->pluck('key')->all();
    }

    /**
     * @return array{key:string,name:string,icon:string,isDefault:bool}
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon,
            'isDefault' => $this->is_default,
        ];
    }

    /** @return array<int, array{key:string,name:string,icon:string,isDefault:bool}> */
    public static function payloadFor(?int $familyId = null): array
    {
        return self::forFamily($familyId)->map(fn (self $l) => $l->toPayload())->values()->all();
    }

    /** Unique, stable slug key for a family from an admin-entered display name. */
    public static function makeKey(string $name, int $familyId): string
    {
        $base = Str::slug($name, '_') ?: 'location';
        $key = $base;
        $existing = static::withoutGlobalScopes()->where('family_id', $familyId)->pluck('key')->all();
        $n = 2;
        while (in_array($key, $existing, true)) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    /**
     * Turn a location value into a stored key: pass an existing key straight through, and mint a custom
     * location when the user typed a new name. Slug-matching first means typing "Food" reuses the
     * built-in `food` rather than creating a duplicate. Lives on the model (moved from the controller) so
     * the web forms and the MCP tools resolve a typed location exactly the same way.
     */
    public static function resolve(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $keys = self::keysFor();                            // also seeds the family's defaults
        if (in_array($value, $keys, true)) {
            return $value;                                  // already a key
        }
        $slug = Str::slug($value, '_');
        if ($slug !== '' && in_array($slug, $keys, true)) {
            return $slug;                                   // typed name maps onto an existing key
        }

        $existing = self::forFamily();
        static::create([
            'key' => $key = self::makeKey($value, (int) CurrentFamily::id()),
            'name' => $value,
            'icon' => self::CUSTOM_ICON,
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1,
        ]);

        return $key;
    }
}
