<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Translations;
use Modules\Shopping\ShoppingAisle;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;
use Modules\Shopping\StoreTemplates;

use function Pest\Laravel\actingAs;

// A family with the shopping module installed, enabled, and active in session.
function shoppingFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('shopping', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/shopping')->assertNotFound();
});

it('renders the module settings page', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->get('/m/shopping/settings')->assertOk();
});

it('materializes default aisles, then adds and deletes a custom one', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure")->assertOk();
    expect(collect($res->json('aisles'))->pluck('slug'))->toContain('produce', 'other')
        ->and($res->json('templates'))->not->toBeEmpty();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles", ['name' => 'Deli', 'icon' => 'ham'])->assertOk();
    $deli = collect($res->json('aisles'))->firstWhere('name', 'Deli');
    expect($deli['slug'])->toBe('deli')->and($deli['icon'])->toBe('ham');

    actingAs($users[0])->deleteJson("/m/shopping/aisles/{$deli['id']}")->assertOk();
    expect(ShoppingAisle::withoutGlobalScopes()->where('slug', 'deli')->exists())->toBeFalse();
});

it('stores an item with a primary store and alternatives', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", [
        'name' => 'Milk', 'store' => 'Metro', 'alt_stores' => ['Dedeman', 'Kaufland'],
    ])->assertRedirect();

    $item = ShoppingItem::withoutGlobalScopes()->where('name', 'Milk')->first();
    expect($item->store)->toBe('Metro')->and($item->alt_stores)->toBe(['Dedeman', 'Kaufland']);
});

it('edits aisles for a chosen store, not just the list default', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure", ['store' => 'Dedeman'])->assertOk();
    expect($res->json('store'))->toBe('Dedeman')
        ->and(ShoppingAisle::withoutGlobalScopes()->where('store', 'Dedeman')->exists())->toBeTrue()
        ->and(ShoppingAisle::withoutGlobalScopes()->where('store', 'Metro')->exists())->toBeFalse();
});

it('applies a shop template, replacing the aisles', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'DIY', 'store' => 'Dedeman']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/template", ['template' => 'bricolage'])->assertOk();
    $slugs = collect($res->json('aisles'))->pluck('slug');
    expect($slugs)->toContain('hand-tools', 'plumbing')->and($slugs)->not->toContain('dairy');
});

it('accepts a custom aisle slug on an item and groups under it', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'DIY', 'store' => 'Dedeman']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/template", ['template' => 'bricolage']);

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Hammer', 'department' => 'hand-tools'])->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->where('name', 'Hammer')->value('department'))->toBe('hand-tools');
});

it('scopes aisles to the family (another family cannot touch them)', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'G', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure")->assertOk();
    $aisleId = $res->json('aisles.0.id');

    // A user from a different family gets a 404 on the family-scoped aisle model binding.
    [, $others] = shoppingFamily();
    actingAs($others[0])->deleteJson("/m/shopping/aisles/{$aisleId}")->assertNotFound();
});

it('creates a list, adds an item, toggles it, and deletes it', function () {
    [, $users] = shoppingFamily();

    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekly Groceries', 'store' => 'Whole Foods'])->assertRedirect();
    $list = ShoppingList::withoutGlobalScopes()->first();
    expect($list->name)->toBe('Weekly Groceries')
        ->and($list->shares()->where('role', 'owner')->exists())->toBeTrue();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Bananas', 'department' => 'produce', 'quantity' => '2 bunches'])
        ->assertRedirect();
    $item = ShoppingItem::withoutGlobalScopes()->first();
    expect($item->name)->toBe('Bananas')->and($item->bought)->toBeFalse();

    // Empty body toggles bought.
    actingAs($users[0])->patch("/m/shopping/items/{$item->id}")->assertRedirect();
    expect($item->fresh()->bought)->toBeTrue();

    actingAs($users[0])->delete("/m/shopping/items/{$item->id}")->assertRedirect();
    // Deletes go to the 30-day trash (soft delete).
    expect(ShoppingItem::withoutGlobalScope('family')->count())->toBe(0);
    $this->assertSoftDeleted($item);
});

it('hides a private list from a member who was not shared', function () {
    [, $users] = shoppingFamily();
    [$alice, $bob] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Surprise', 'visibility' => 'private']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $access = app(ShareAccess::class);
    expect($access->canView($alice, $list))->toBeTrue()   // owner sees it
        ->and($access->canView($bob, $list))->toBeFalse(); // other member does not
});

it('lets any member add to a family-visible list', function () {
    [, $users] = shoppingFamily();
    [$alice, $bob] = $users;

    actingAs($alice)->post('/m/shopping/lists', ['name' => 'Shared list']); // defaults to family visibility
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($bob)->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Eggs'])->assertRedirect();
    expect($list->items()->count())->toBe(1);
});

it('renames, changes visibility, and archives via updateList', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->patch("/m/shopping/lists/{$list->id}", ['name' => 'Renamed', 'visibility' => 'private', 'archived' => true])->assertRedirect();

    $list->refresh();
    expect($list->name)->toBe('Renamed')
        ->and($list->visibility())->toBe('private')
        ->and($list->archived_at)->not->toBeNull();
});

it('bulk-adds reviewed voice items to the list', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items/bulk", [
        'items' => [
            ['name' => 'Milk', 'quantity' => '1 gallon', 'department' => 'dairy'],
            ['name' => 'Bananas', 'department' => 'produce', 'note' => 'for the kids'],
        ],
    ])->assertRedirect();

    expect($list->items()->count())->toBe(2)
        ->and($list->items()->where('name', 'Milk')->value('department'))->toBe('dairy')
        ->and($list->items()->where('name', 'Bananas')->value('note'))->toBe('for the kids');
});

it('404s the voice parse endpoint when AI is not configured', function () {
    config()->set('services.gemini.key', null);
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/parse", ['transcript' => 'milk and eggs'])->assertNotFound();
});

it('parses a pasted block into one item per line, stripping list noise and capturing counts', function () {
    config()->set('services.gemini.key', null); // naive split only, no model call
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $block = "- [ ] milk\n* 2x eggs\n1. Bananas 🍌\n\n\n3) flour x2\n☐ 2 kg potatoes\n";
    $items = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => $block])
        ->assertOk()->json('items');

    expect(array_column($items, 'name'))->toBe(['milk', 'eggs', 'Bananas', 'flour', 'potatoes'])
        ->and(array_column($items, 'quantity'))->toBe([null, '2', null, '2', '2 kg']);

    // The review screen then bulk-adds exactly what was parsed: one row per pasted line.
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items/bulk", ['items' => $items])->assertRedirect();
    expect($list->items()->count())->toBe(5)
        ->and($list->items()->where('name', 'eggs')->value('quantity'))->toBe('2');
});

it('splits a comma-separated pasted line into items', function () {
    config()->set('services.gemini.key', null);
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    $items = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => 'milk, eggs (3), 2 lapte'])->json('items');

    expect(array_column($items, 'name'))->toBe(['milk', 'eggs', 'lapte'])
        ->and(array_column($items, 'quantity'))->toBe([null, '3', '2']);
});

it('paste respects list permissions and family scope', function () {
    config()->set('services.gemini.key', null);
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Mine', 'visibility' => 'private']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    // Same family, no share on a private list: no role => forbidden.
    actingAs($users[1])->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => 'milk'])->assertForbidden();
    // Another family is denied too. 404 when the family scope catches it at binding time, 403 from
    // the share check otherwise (route bindings resolve before SetActiveFamily runs, so the scope
    // can't be relied on at bind time; roleFor() is the real guard). Either way: no access.
    [, $others] = shoppingFamily();
    expect(actingAs($others[0])->postJson("/m/shopping/lists/{$list->id}/paste", ['text' => 'milk'])->status())->toBeIn([403, 404]);
});

it('duplicates a list with its items reset to unbought', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Original', 'store' => 'Target']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'department' => 'dairy']);
    ShoppingItem::withoutGlobalScopes()->first()->update(['bought' => true]);

    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/duplicate")->assertRedirect();

    $copy = ShoppingList::withoutGlobalScopes()->where('name', 'Original (copy)')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->store)->toBe('Target')
        ->and($copy->items()->count())->toBe(1)
        ->and($copy->items()->first()->bought)->toBeFalse()  // reset for the next trip
        ->and($copy->shares()->where('role', 'owner')->exists())->toBeTrue();
});

it('only offers members with access as assignees on a private list', function () {
    [, $users] = shoppingFamily(); // owner + one other member
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Secret']);
    $list = ShoppingList::withoutGlobalScopes()->latest('id')->first();
    $list->setVisibility('private');

    actingAs($users[0])->get("/m/shopping/lists/{$list->id}")
        ->assertInertia(fn ($p) => $p->has('members', 1)->where('members.0.id', $users[0]->id));
});

it('aggregates a shop\'s items across accessible lists, including "also available at"', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Casa']);
    $lists = ShoppingList::withoutGlobalScopes()->orderBy('id')->get();

    actingAs($users[0])->post("/m/shopping/lists/{$lists[0]->id}/items", ['name' => 'Bananas', 'store' => 'Metro']);
    actingAs($users[0])->post("/m/shopping/lists/{$lists[1]->id}/items", ['name' => 'Apples', 'store' => 'Metro']);
    // Primary Kaufland but also available at Metro -> shows under both.
    actingAs($users[0])->post("/m/shopping/lists/{$lists[0]->id}/items", ['name' => 'Milk', 'store' => 'Kaufland', 'alt_stores' => ['Metro']]);

    $metro = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();
    actingAs($users[0])->get("/m/shopping/shops/{$metro->id}")
        ->assertInertia(fn ($p) => $p->component('shopping/shop')->has('items', 3));

    $kaufland = ShoppingShop::withoutGlobalScopes()->where('name', 'Kaufland')->first();
    actingAs($users[0])->get("/m/shopping/shops/{$kaufland->id}")
        ->assertInertia(fn ($p) => $p->has('items', 1)); // only Milk
});

it('renames a shop and cascades the name across items', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Bananas', 'store' => 'Metro']);
    $shop = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();

    actingAs($users[0])->patch("/m/shopping/shops/{$shop->id}", ['name' => 'Metro Cash & Carry'])->assertRedirect();

    expect(ShoppingShop::withoutGlobalScopes()->find($shop->id)->name)->toBe('Metro Cash & Carry')
        ->and(ShoppingItem::withoutGlobalScopes()->where('name', 'Bananas')->first()->store)->toBe('Metro Cash & Carry');
});

it('adds an item from a shop page into the default list with the shop preset', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekend']);
    $list = ShoppingList::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Milk', 'store' => 'Metro']);
    $shop = ShoppingShop::withoutGlobalScopes()->where('name', 'Metro')->first();

    // No list_id -> lands in the user's default (most recent) list, with Metro preset as the store.
    actingAs($users[0])->post("/m/shopping/shops/{$shop->id}/items", ['name' => 'Cheese'])->assertRedirect();

    $item = ShoppingItem::withoutGlobalScopes()->where('name', 'Cheese')->first();
    expect($item->store)->toBe('Metro')->and($item->shopping_list_id)->toBe($list->id);
});

it('creates a shop from the Shops tab', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/shops', ['name' => 'Lidl'])->assertRedirect();
    expect(ShoppingShop::withoutGlobalScopes()->where('name', 'Lidl')->exists())->toBeTrue();
});

it('collects items with no shop into the No shop bucket', function () {
    [, $users] = shoppingFamily();
    // A storeless list: its items belong to no shop unless they set one of their own.
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Misc']);
    $noStore = ShoppingList::withoutGlobalScopes()->where('name', 'Misc')->first();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Groceries', 'store' => 'Metro']);
    $withStore = ShoppingList::withoutGlobalScopes()->where('name', 'Groceries')->first();

    actingAs($users[0])->post("/m/shopping/lists/{$noStore->id}/items", ['name' => 'Batteries']); // no shop
    actingAs($users[0])->post("/m/shopping/lists/{$withStore->id}/items", ['name' => 'Milk']);     // inherits Metro

    // The Shops tab counts exactly the one unassigned item.
    actingAs($users[0])->get('/m/shopping/shops')->assertInertia(fn ($p) => $p->where('noShopCount', 1));

    // The No shop page lists only that item, in virtual mode.
    actingAs($users[0])->get('/m/shopping/shops/none')->assertInertia(fn ($p) => $p
        ->where('virtual', true)
        ->has('items', 1)
        ->where('items.0.name', 'Batteries'));
});

it('sets a list shop, promoting it and making storeless items inherit it', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Misc']);
    $list = ShoppingList::withoutGlobalScopes()->where('name', 'Misc')->first();
    actingAs($users[0])->post("/m/shopping/lists/{$list->id}/items", ['name' => 'Batteries']); // no own shop

    // Before: the item belongs to no shop.
    actingAs($users[0])->get('/m/shopping/shops')->assertInertia(fn ($p) => $p->where('noShopCount', 1));

    // Set the list's shop via the edit drawer's PATCH.
    actingAs($users[0])->patch("/m/shopping/lists/{$list->id}", ['store' => 'Kaufland'])->assertRedirect();

    // The store became a real shop, and the storeless item inherited it (out of the No shop bucket).
    expect(ShoppingShop::withoutGlobalScopes()->where('name', 'Kaufland')->exists())->toBeTrue();
    actingAs($users[0])->get('/m/shopping/shops')->assertInertia(fn ($p) => $p
        ->where('noShopCount', 0)
        ->where('shops', fn ($shops) => collect($shops)->firstWhere('name', 'Kaufland')['toBuy'] === 1));
});

it('promotes the shop when a list is created with one', function () {
    [, $users] = shoppingFamily();
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Pharmacy', 'store' => 'Farmacia Tei'])->assertRedirect();

    $list = ShoppingList::withoutGlobalScopes()->where('name', 'Pharmacy')->first();
    expect($list->store)->toBe('Farmacia Tei')
        ->and(ShoppingShop::withoutGlobalScopes()->where('name', 'Farmacia Tei')->exists())->toBeTrue();
});

// Built-in aisle names are stored in English but shown in the viewer's language; the slug, which items
// reference, never changes, and a name the family typed is left alone.
it('shows built-in aisle names in the viewer locale without touching stored data', function () {
    [, $users] = shoppingFamily();
    $users[0]->update(['locale' => 'de']);
    actingAs($users[0])->post('/m/shopping/lists', ['name' => 'Weekly', 'store' => 'Metro']);
    $list = ShoppingList::withoutGlobalScopes()->first();

    // Virtual default set (no rows yet).
    actingAs($users[0])->get("/m/shopping/lists/{$list->id}")
        ->assertInertia(fn ($p) => $p->where('defaultAisles.0.slug', 'produce')
            ->where('defaultAisles.0.name', 'Obst & Gemüse')
            ->where('defaultAisles.1.name', 'Milch & Eier')
            ->where('defaultAisles.2.name', 'Backwaren')
            ->where('defaultAisles.3.name', 'Vorrat'));

    // Materialised rows keep English in the DB; the editor payload is translated, a custom rename is not.
    $res = actingAs($users[0])->postJson("/m/shopping/lists/{$list->id}/aisles/ensure")->assertOk();
    expect(collect($res->json('aisles'))->firstWhere('slug', 'produce')['name'])->toBe('Obst & Gemüse');
    $produce = ShoppingAisle::where('store', 'Metro')->where('slug', 'produce')->first();
    expect($produce->name)->toBe('Produce');

    // Saving the translated name untouched (blur) must not overwrite the English original.
    actingAs($users[0])->patchJson("/m/shopping/aisles/{$produce->id}", ['name' => 'Obst & Gemüse'])->assertOk();
    expect($produce->fresh()->name)->toBe('Produce');

    $dairy = ShoppingAisle::where('store', 'Metro')->where('slug', 'dairy')->first();
    $res = actingAs($users[0])->patchJson("/m/shopping/aisles/{$dairy->id}", ['name' => 'Kühlregal'])->assertOk();
    expect(collect($res->json('aisles'))->firstWhere('slug', 'dairy')['name'])->toBe('Kühlregal');
});

it('translates every built-in aisle and template name in every locale', function () {
    foreach (array_keys(Translations::LOCALES) as $locale) {
        if ($locale === 'en') {
            continue;
        }
        foreach (StoreTemplates::TEMPLATES as $aisles) {
            foreach ($aisles as [$slug, $name]) {
                $shown = Translations::get('shopping.aisle_'.str_replace('-', '_', $slug), $locale);
                expect($shown)->not->toStartWith('shopping.');
            }
        }
    }
    app()->setLocale('de');
    expect(collect(StoreTemplates::options())->pluck('label')->all())->toBe(['Lebensmittel', 'Heimwerken / DIY', 'Apotheke', 'Leer']);
});
