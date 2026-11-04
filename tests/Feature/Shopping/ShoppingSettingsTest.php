<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingSetting;
use Modules\Shopping\ShoppingShop;

function shoppingSettingsUser(): User
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');
    app(ModuleRegistry::class)->enable('shopping', $family->id);
    CurrentFamily::set($family->id);
    test()->actingAs($user);

    return $user;
}

it('saves Shopping settings and starts new lists with the default shop', function () {
    shoppingSettingsUser();
    $this->patch('/m/shopping/settings', ['default_shop' => 'Lidl', 'sort' => 'az', 'warn_duplicates' => false, 'clean_after_days' => 14])->assertRedirect();
    $s = ShoppingSetting::current();
    expect($s->default_shop)->toBe('Lidl')->and($s->sort)->toBe('az')->and($s->warn_duplicates)->toBeFalse()->and($s->clean_after_days)->toBe(14);

    $this->patch('/m/shopping/settings', ['clean_after_days' => 5])->assertSessionHasErrors('clean_after_days');
    $this->get('/m/shopping')->assertInertia(fn ($p) => $p->where('defaultShop', 'Lidl'));
});

it('adds a duplicate without asking when the warning is off', function () {
    shoppingSettingsUser();
    $list = ShoppingList::create(['name' => 'Weekly', 'created_by' => auth()->id()]);
    $list->items()->create(['created_by' => auth()->id(), 'name' => 'Milk']);
    $this->post("/m/shopping/lists/{$list->id}/items", ['name' => 'milk'])->assertSessionHasErrors('add_anyway');

    ShoppingSetting::current()->update(['warn_duplicates' => false]);
    $this->post("/m/shopping/lists/{$list->id}/items", ['name' => 'milk'])->assertSessionHasNoErrors();
    expect($list->items()->count())->toBe(2);
});

it('stamps bought_at on tick and clears it on untick', function () {
    shoppingSettingsUser();
    $item = ShoppingList::create(['name' => 'W', 'created_by' => auth()->id()])->items()->create(['created_by' => auth()->id(), 'name' => 'Eggs']);
    $item->update(['bought' => true]);
    expect($item->fresh()->bought_at)->not->toBeNull();
    $item->update(['bought' => false]);
    expect($item->fresh()->bought_at)->toBeNull();
});

it('auto-cleans bought items after the family\'s days, honouring a list\'s own setting', function () {
    shoppingSettingsUser();
    $weekly = ShoppingList::create(['name' => 'Weekly', 'created_by' => auth()->id()]);
    $keep = ShoppingList::create(['name' => 'Keep', 'created_by' => auth()->id()]);
    $keep->forceFill(['clean_after_days' => 0])->save();
    foreach ([$weekly, $keep] as $list) {
        $old = $list->items()->create(['created_by' => auth()->id(), 'name' => 'Old', 'bought' => true]);
        $old->forceFill(['bought_at' => now()->subDays(8)])->saveQuietly();
        $list->items()->create(['created_by' => auth()->id(), 'name' => 'Fresh', 'bought' => true]);
        $list->items()->create(['created_by' => auth()->id(), 'name' => 'Open']);
    }

    $this->artisan('shopping:clean-bought')->assertSuccessful();

    expect($weekly->items()->pluck('name')->sort()->values()->all())->toBe(['Fresh', 'Open'])
        ->and($keep->items()->count())->toBe(3)
        ->and(ShoppingItem::onlyTrashed()->where('name', 'Old')->count())->toBe(1);
});

it('lists your shops in their dragged order', function () {
    shoppingSettingsUser();
    $a = ShoppingShop::create(['name' => 'Lidl']);
    $b = ShoppingShop::create(['name' => 'Kaufland']);
    $this->postJson('/m/shopping/shops/reorder', ['ids' => [$a->id, $b->id]])->assertOk();
    $this->get('/m/shopping/settings/shops')->assertInertia(fn ($p) => $p->component('shopping/your-shops')
        ->where('shops.0.name', 'Lidl')->where('shops.0.logo', '/brands/lidl.png')->where('shops.1.name', 'Kaufland'));
});

it('saves a list\'s own sort, bought and auto-clean, and null goes back to the family default (S2b, S2c)', function () {
    shoppingSettingsUser();
    ShoppingSetting::current()->update(['sort' => 'az', 'clean_after_days' => 3]);
    $list = ShoppingList::createFor(auth()->user(), ['name' => 'Weekly']);

    $this->get("/m/shopping/lists/{$list->id}")->assertInertia(fn ($p) => $p
        ->where('list.sort', null)->where('list.cleanAfterDays', null)
        ->where('defaults.sort', 'az')->where('defaults.cleanAfterDays', 3));

    $this->patch("/m/shopping/lists/{$list->id}", ['sort' => 'added', 'bought' => 'hide', 'clean_after_days' => 0])->assertSessionHasNoErrors();
    expect($list->fresh()->only(['sort', 'bought', 'clean_after_days']))->toBe(['sort' => 'added', 'bought' => 'hide', 'clean_after_days' => 0]);

    $this->patch("/m/shopping/lists/{$list->id}", ['clean_after_days' => null, 'sort' => null])->assertSessionHasNoErrors();
    expect($list->fresh()->clean_after_days)->toBeNull()->and($list->fresh()->sort)->toBeNull();
    $this->patch("/m/shopping/lists/{$list->id}", ['clean_after_days' => 5])->assertSessionHasErrors('clean_after_days');
});

it('clears a list\'s bought items now, into Recently deleted', function () {
    shoppingSettingsUser();
    $list = ShoppingList::createFor(auth()->user(), ['name' => 'Weekly']);
    $list->items()->create(['created_by' => auth()->id(), 'name' => 'Eggs', 'bought' => true]);
    $list->items()->create(['created_by' => auth()->id(), 'name' => 'Milk']);

    $this->post("/m/shopping/lists/{$list->id}/clear-bought")->assertRedirect();
    expect($list->items()->pluck('name')->all())->toBe(['Milk'])
        ->and(ShoppingItem::onlyTrashed()->where('name', 'Eggs')->exists())->toBeTrue();
});
