<?php

namespace Modules\Shopping;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

class ShoppingAisle extends Model
{
    use BelongsToFamily;

    protected $table = 'shopping_aisles';

    protected $fillable = ['store', 'slug', 'name', 'icon', 'position'];

    /**
     * A shop's aisles as {slug,name,icon} in walk order, falling back to the default Grocery set
     * when the shop has no custom rows. Takes the family explicitly (bypassing the request-scoped
     * global scope) so it also works from deferred callbacks and console commands.
     *
     * @return list<array{slug: string, name: string, icon: string}>
     */
    public static function resolve(?string $store, int $familyId): array
    {
        $rows = static::withoutGlobalScope('family')->where('family_id', $familyId)
            ->where('store', (string) $store)->orderBy('position')->orderBy('id')->get();
        if ($rows->isNotEmpty()) {
            return $rows->map(fn (self $a) => ['slug' => $a->slug, 'name' => $a->name, 'icon' => $a->icon])->all();
        }

        return array_map(fn ($t) => ['slug' => $t[0], 'name' => $t[1], 'icon' => $t[2]], StoreTemplates::default());
    }
}
