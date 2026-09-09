<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\InventoryAlert;
use Modules\Inventory\InventoryCategory;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryLocation;
use Modules\Inventory\InventoryLog;
use Modules\Inventory\InventorySetting;
use Modules\Notifications\PushAlert;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;

use function Pest\Laravel\actingAs;

// A family with the inventory module installed, enabled, and active in session + context (so items
// seeded directly in the test get the right family_id and the global scope applies).
function inventoryFamily(int $members = 1): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('inventory', $family->id);

    return [$family, $users];
}

// Seed an item for the current family with sensible defaults, overridable per case.
function makeItem(array $attrs = []): InventoryItem
{
    return InventoryItem::create(array_merge([
        'name' => 'Thing',
        'quantity' => 5,
        'unit' => 'pieces',
        'category' => 'food',
        'location' => 'pantry',
        'min_quantity' => 0,
        'auto_replenish' => true,
    ], $attrs));
}

it('404s the module pages when inventory is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/inventory')->assertNotFound();
    actingAs($user)->get('/m/inventory/items')->assertNotFound();
});

it('reaches the module pages once enabled, and 404s again after disabling', function () {
    [$family, $users] = inventoryFamily();

    actingAs($users[0])->get('/m/inventory')->assertOk();

    app(ModuleRegistry::class)->disable('inventory', $family->id);
    actingAs($users[0])->get('/m/inventory')->assertNotFound();
});

it('renders the overview with real data and correct summary counts', function () {
    [, $users] = inventoryFamily();

    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);              // good
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);              // low
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);             // empty
    makeItem(['name' => 'Yogurt', 'quantity' => 3, 'expires_at' => now()->addDays(2)]); // expiring
    makeItem(['name' => 'Salt', 'quantity' => null]);                                // unknown

    actingAs($users[0])->get('/m/inventory')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/overview')
            ->where('summary.total', 5)
            ->where('summary.low', 1)
            ->where('summary.empty', 1)
            ->where('summary.expiring', 1)
            ->has('attention', 3) // low + empty + expiring need attention (good + unknown do not)
            ->etc()
    );
});

it('renders all items with each item carrying its derived state', function () {
    [, $users] = inventoryFamily();
    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);
    makeItem(['name' => 'Salt', 'quantity' => null]);

    actingAs($users[0])->get('/m/inventory/items')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/items')
            ->has('items', 3)
            ->has('categories')
            ->has('locations')
            ->where('statuses', InventoryItem::STATES)
            ->etc()
    );
});

it('renders the item detail with its history and derived bar', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['name' => 'Parmesan', 'quantity' => 120, 'unit' => 'g', 'min_quantity' => 100, 'target_quantity' => 400]);
    InventoryLog::create(['inventory_item_id' => $item->id, 'delta' => -30, 'type' => 'consumed', 'user_id' => $users[0]->id]);

    actingAs($users[0])->get("/m/inventory/items/{$item->id}")->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/item')
            ->where('item.name', 'Parmesan')
            ->where('item.state', 'good')      // 120 > min 100, not near expiry
            ->where('item.bar', 0.3)           // 120 / 400 target
            ->has('history', 1)
            ->etc()
    );
});

it('computes every state correctly from quantity/min/expiry', function () {
    [, $users] = inventoryFamily();

    expect(makeItem(['quantity' => 8, 'min_quantity' => 2])->state())->toBe('good');
    expect(makeItem(['quantity' => 2, 'min_quantity' => 2])->state())->toBe('low');     // at min => low
    expect(makeItem(['quantity' => 1, 'min_quantity' => 2])->state())->toBe('low');
    expect(makeItem(['quantity' => 0, 'min_quantity' => 2])->state())->toBe('empty');
    expect(makeItem(['quantity' => null])->state())->toBe('unknown');
    expect(makeItem(['quantity' => 5, 'expires_at' => now()->addDays(3)])->state())->toBe('expiring');
    expect(makeItem(['quantity' => 5, 'expires_at' => now()->subDay()])->state())->toBe('expired');
    // Precedence: expired beats empty; empty beats expiring.
    expect(makeItem(['quantity' => 0, 'expires_at' => now()->subDay()])->state())->toBe('expired');
    expect(makeItem(['quantity' => 0, 'expires_at' => now()->addDay()])->state())->toBe('empty');
    // min 0 disables the low threshold (a positive quantity with no min is good, never low).
    expect(makeItem(['quantity' => 1, 'min_quantity' => 0])->state())->toBe('good');

    // The detail page reflects the derived state on the wire.
    $low = makeItem(['name' => 'Butter', 'quantity' => 1, 'min_quantity' => 3]);
    actingAs($users[0])->get("/m/inventory/items/{$low->id}")
        ->assertInertia(fn ($p) => $p->where('item.state', 'low')->etc());
});

it('does not leak items across families', function () {
    [$familyA, $usersA] = inventoryFamily();
    $itemA = makeItem(['name' => 'A-Only']);

    // A second family, its own item.
    $userB = User::factory()->create();
    $familyB = Family::factory()->create(['owner_id' => $userB->id]);
    $familyB->members()->attach($userB, ['role' => 'admin']);
    CurrentFamily::set($familyB->id);
    app(ModuleRegistry::class)->enable('inventory', $familyB->id);
    $itemB = InventoryItem::create(['name' => 'B-Only', 'quantity' => 1, 'family_id' => $familyB->id]);

    // Back to family A's session: the overview sees only A's item, and B's item detail 404s.
    session(['active_family_id' => $familyA->id]);
    actingAs($usersA[0])->get('/m/inventory')
        ->assertInertia(fn ($p) => $p->where('summary.total', 1)->etc());

    actingAs($usersA[0])->get("/m/inventory/items/{$itemB->id}")->assertNotFound();
    actingAs($usersA[0])->get("/m/inventory/items/{$itemA->id}")->assertOk();
});

// ---------------------------------------------------------------------------------------------------
// Phase 2: writes + history log
// ---------------------------------------------------------------------------------------------------

it('creates an item, logs its opening stock, and derives the right state', function () {
    [$family, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/items', [
        'name' => 'Eggs',
        'quantity' => 12,
        'unit' => 'pieces',
        'category' => 'food',
        'location' => 'fridge',
        'min_quantity' => 6,
        'auto_replenish' => true,
    ])->assertRedirect();

    $item = InventoryItem::where('name', 'Eggs')->firstOrFail();
    expect($item->quantity)->toBe(12.0);
    expect($item->created_by)->toBe($users[0]->id);
    expect($item->state())->toBe('good');           // 12 > min 6

    // The creation writes one "added" log row carrying the opening quantity.
    $log = $item->logs()->firstOrFail();
    expect($log->type)->toBe('added');
    expect($log->delta)->toBe(12.0);
    expect($log->user_id)->toBe($users[0]->id);
    expect($log->family_id)->toBe($family->id);
});

it('creates an unknown-quantity item (null delta log) in the unknown state', function () {
    [, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/items', [
        'name' => 'Batteries',
        'quantity' => null,
        'unit' => 'pieces',
    ])->assertRedirect();

    $item = InventoryItem::where('name', 'Batteries')->firstOrFail();
    expect($item->quantity)->toBeNull();
    expect($item->state())->toBe('unknown');
    expect($item->logs()->firstOrFail()->delta)->toBeNull();
});

it('a quick consume down to 0 empties the item and logs a negative delta', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['name' => 'Milk', 'quantity' => 3, 'min_quantity' => 1, 'unit' => 'L']);

    actingAs($users[0])->post("/m/inventory/items/{$item->id}/adjust", ['quantity' => 0])->assertRedirect();

    $item->refresh();
    expect($item->quantity)->toBe(0.0);
    expect($item->state())->toBe('empty');

    $log = $item->logs()->firstOrFail();
    expect($log->type)->toBe('consumed');
    expect($log->delta)->toBe(-3.0);                 // 0 - 3
});

it('a quick add logs a positive delta and recomputes the state', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 2, 'unit' => 'kg']);
    expect($item->state())->toBe('low');

    actingAs($users[0])->post("/m/inventory/items/{$item->id}/adjust", ['quantity' => 5])->assertRedirect();

    $item->refresh();
    expect($item->quantity)->toBe(5.0);
    expect($item->state())->toBe('good');
    $log = $item->logs()->firstOrFail();
    expect($log->type)->toBe('added');
    expect($log->delta)->toBe(4.0);                  // 5 - 1
});

it('a quick update with no change writes no log row', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['quantity' => 4]);

    actingAs($users[0])->post("/m/inventory/items/{$item->id}/adjust", ['quantity' => 4])->assertRedirect();

    expect($item->logs()->count())->toBe(0);
});

it('editing quantity below the minimum flips the item to low and logs an adjustment', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['name' => 'Coffee', 'quantity' => 10, 'min_quantity' => 3, 'unit' => 'packs']);
    expect($item->state())->toBe('good');

    actingAs($users[0])->patch("/m/inventory/items/{$item->id}", [
        'name' => 'Coffee',
        'quantity' => 2,
        'unit' => 'packs',
        'min_quantity' => 3,
        'auto_replenish' => true,
    ])->assertRedirect();

    $item->refresh();
    expect($item->quantity)->toBe(2.0);
    expect($item->state())->toBe('low');
    $log = $item->logs()->firstOrFail();
    expect($log->type)->toBe('adjusted');
    expect($log->delta)->toBe(-8.0);                 // 2 - 10
});

it('an edit that leaves the quantity unchanged writes no log row', function () {
    [, $users] = inventoryFamily();
    $item = makeItem(['name' => 'Sugar', 'quantity' => 5]);

    actingAs($users[0])->patch("/m/inventory/items/{$item->id}", [
        'name' => 'Brown sugar', // rename only
        'quantity' => 5,
        'unit' => 'pieces',
        'auto_replenish' => true,
    ])->assertRedirect();

    $item->refresh();
    expect($item->name)->toBe('Brown sugar');
    expect($item->logs()->count())->toBe(0);
});

it('deletes an item and its history without touching other items', function () {
    [, $users] = inventoryFamily();
    $keep = makeItem(['name' => 'Keep', 'quantity' => 5]);
    $gone = makeItem(['name' => 'Gone', 'quantity' => 5]);
    InventoryLog::create(['inventory_item_id' => $gone->id, 'delta' => -1, 'type' => 'consumed', 'user_id' => $users[0]->id]);

    actingAs($users[0])->delete("/m/inventory/items/{$gone->id}")->assertRedirect('/m/inventory/items');

    expect(InventoryItem::find($gone->id))->toBeNull();
    expect(InventoryLog::where('inventory_item_id', $gone->id)->count())->toBe(0); // cascaded, not orphaned
    expect(InventoryItem::find($keep->id))->not->toBeNull();                       // other items untouched
});

it('resolves a typed category/location name into a new custom key', function () {
    [$family, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/items', [
        'name' => 'Cat food',
        'quantity' => 2,
        'unit' => 'boxes',
        'category' => 'Pet',       // brand-new category, typed inline
        'location' => 'Balcony',   // brand-new location, typed inline
    ])->assertRedirect();

    $item = InventoryItem::where('name', 'Cat food')->firstOrFail();
    expect($item->category)->toBe('pet');
    expect($item->location)->toBe('balcony');
    expect(InventoryCategory::where('key', 'pet')->where('is_default', false)->exists())->toBeTrue();
    expect(InventoryLocation::where('key', 'balcony')->where('is_default', false)->exists())->toBeTrue();
});

it('reuses a built-in category when its display name is typed (no duplicate)', function () {
    [, $users] = inventoryFamily();
    InventoryCategory::ensureDefaults();

    actingAs($users[0])->post('/m/inventory/items', [
        'name' => 'Bread',
        'quantity' => 1,
        'unit' => 'pieces',
        'category' => 'Food', // slugs to the built-in `food`
    ])->assertRedirect();

    expect(InventoryItem::where('name', 'Bread')->firstOrFail()->category)->toBe('food');
    expect(InventoryCategory::where('key', 'food')->count())->toBe(1); // not duplicated
});

it('validates the write payload', function () {
    [, $users] = inventoryFamily();
    $item = makeItem();

    // name is required
    actingAs($users[0])->post('/m/inventory/items', ['unit' => 'pieces'])
        ->assertSessionHasErrors('name');

    // unit must be one of the allowed set
    actingAs($users[0])->post('/m/inventory/items', ['name' => 'X', 'unit' => 'furlongs'])
        ->assertSessionHasErrors('unit');

    // quantity must be numeric and non-negative
    actingAs($users[0])->post('/m/inventory/items', ['name' => 'X', 'unit' => 'pieces', 'quantity' => 'lots'])
        ->assertSessionHasErrors('quantity');
    actingAs($users[0])->post('/m/inventory/items', ['name' => 'X', 'unit' => 'pieces', 'quantity' => -1])
        ->assertSessionHasErrors('quantity');

    // adjust needs a numeric, non-negative quantity
    actingAs($users[0])->post("/m/inventory/items/{$item->id}/adjust", ['quantity' => -5])
        ->assertSessionHasErrors('quantity');
});

it('will not let a write touch another family\'s item', function () {
    [$familyA, $usersA] = inventoryFamily();

    $userB = User::factory()->create();
    $familyB = Family::factory()->create(['owner_id' => $userB->id]);
    $familyB->members()->attach($userB, ['role' => 'admin']);
    CurrentFamily::set($familyB->id);
    app(ModuleRegistry::class)->enable('inventory', $familyB->id);
    $itemB = InventoryItem::create(['name' => 'B-Only', 'quantity' => 5, 'family_id' => $familyB->id]);

    session(['active_family_id' => $familyA->id]);
    CurrentFamily::set($familyA->id);

    actingAs($usersA[0])->post("/m/inventory/items/{$itemB->id}/adjust", ['quantity' => 0])->assertNotFound();
    actingAs($usersA[0])->delete("/m/inventory/items/{$itemB->id}")->assertNotFound();

    expect(InventoryItem::withoutGlobalScopes()->find($itemB->id)->quantity)->toBe(5.0);
});

// ---------------------------------------------------------------------------------------------------
// Phase 3: Needs Attention buckets + Categories/Locations + centralised state logic
// ---------------------------------------------------------------------------------------------------

// Push an item's updated_at into the past without tripping the auto-touch (for the "stale" bucket).
function ageItem(InventoryItem $item, int $days): InventoryItem
{
    $item->timestamps = false;
    $item->updated_at = now()->subDays($days);
    $item->save();
    $item->timestamps = true;

    return $item->refresh();
}

it('sorts items into the right Needs Attention buckets, one bucket each', function () {
    [, $users] = inventoryFamily();

    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);                       // good — excluded
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);                       // low
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);                       // empty
    makeItem(['name' => 'Yogurt', 'quantity' => 3, 'expires_at' => now()->addDays(2)]);       // expiring
    makeItem(['name' => 'Ham', 'quantity' => 2, 'expires_at' => now()->subDay()]);            // expired

    actingAs($users[0])->get('/m/inventory/needs-attention')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/needs-attention')
            ->has('low', 1)
            ->has('empty', 1)
            ->has('expiring', 2)   // expiring + already-expired share the "Expiring soon" bucket
            ->has('stale', 0)
            ->where('total', 4)    // good is not counted
            ->where('low.0.name', 'Milk')
            ->where('empty.0.name', 'Eggs')
    );
});

it('flags an otherwise-fine item as stale only after it goes quiet, never a low item', function () {
    [, $users] = inventoryFamily();

    $quiet = makeItem(['name' => 'Vinegar', 'quantity' => 5, 'min_quantity' => 1]);           // good…
    ageItem($quiet, 60);                                                                        // …but untouched 60d
    $fresh = makeItem(['name' => 'Oil', 'quantity' => 5, 'min_quantity' => 1]);                // good + recent
    $oldLow = makeItem(['name' => 'Flour', 'quantity' => 1, 'min_quantity' => 3]);             // low…
    ageItem($oldLow, 60);                                                                        // …and old, but low wins

    expect($quiet->isStale())->toBeTrue();
    expect($fresh->isStale())->toBeFalse();
    expect($oldLow->isStale())->toBeFalse(); // an actionable item is never stale (no double-count)

    actingAs($users[0])->get('/m/inventory/needs-attention')->assertInertia(
        fn ($p) => $p->has('stale', 1)->where('stale.0.name', 'Vinegar')->has('low', 1)->where('low.0.name', 'Flour')->etc()
    );
});

it('computes category counts (items + attention), family-scoped, ignoring unknowns', function () {
    [$familyA, $usersA] = inventoryFamily();

    makeItem(['name' => 'Rice', 'category' => 'food', 'quantity' => 8, 'min_quantity' => 2]);   // food, good
    makeItem(['name' => 'Milk', 'category' => 'food', 'quantity' => 1, 'min_quantity' => 2]);   // food, low
    makeItem(['name' => 'Salt', 'category' => 'food', 'quantity' => null, 'min_quantity' => 5]); // food, unknown (not low/empty)

    // A second family's food item must not bleed into family A's counts.
    $userB = User::factory()->create();
    $familyB = Family::factory()->create(['owner_id' => $userB->id]);
    $familyB->members()->attach($userB, ['role' => 'admin']);
    CurrentFamily::set($familyB->id);
    app(ModuleRegistry::class)->enable('inventory', $familyB->id);
    InventoryItem::create(['name' => 'B-Food', 'category' => 'food', 'quantity' => 0, 'min_quantity' => 1, 'family_id' => $familyB->id]);

    session(['active_family_id' => $familyA->id]);
    CurrentFamily::set($familyA->id);

    actingAs($usersA[0])->get('/m/inventory/categories')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/categories')
            ->where('categories.0.key', 'food')
            ->where('categories.0.count', 3)      // 3 food items in family A (unknown included in the total)
            ->where('categories.0.attention', 1) // only the low one needs attention; unknown does not
            ->etc()
    );
});

it('computes location counts (items + low + empty), ignoring unknowns', function () {
    [, $users] = inventoryFamily();

    makeItem(['name' => 'Pasta', 'location' => 'kitchen', 'quantity' => 8, 'min_quantity' => 2]);  // good
    makeItem(['name' => 'Beans', 'location' => 'kitchen', 'quantity' => 1, 'min_quantity' => 2]);  // low
    makeItem(['name' => 'Peas', 'location' => 'kitchen', 'quantity' => 0, 'min_quantity' => 2]);   // empty
    makeItem(['name' => 'Herbs', 'location' => 'kitchen', 'quantity' => null]);                    // unknown

    actingAs($users[0])->get('/m/inventory/locations')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/locations')
            ->where('locations.0.key', 'kitchen')
            ->where('locations.0.count', 4)
            ->where('locations.0.low', 1)
            ->where('locations.0.empty', 1)     // unknown counts toward neither low nor empty
            ->etc()
    );
});

it('lets an admin add a custom category with an icon and assign it to an item', function () {
    [, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/categories', ['name' => 'Pet', 'icon' => 'dog'])->assertRedirect();

    $cat = InventoryCategory::where('key', 'pet')->firstOrFail();
    expect($cat->is_default)->toBeFalse();
    expect($cat->icon)->toBe('dog');

    // The new category can be assigned to an item.
    actingAs($users[0])->post('/m/inventory/items', ['name' => 'Kibble', 'quantity' => 2, 'unit' => 'boxes', 'category' => 'pet'])->assertRedirect();
    expect(InventoryItem::where('name', 'Kibble')->firstOrFail()->category)->toBe('pet');
});

it('lets an admin add a custom location and assign it', function () {
    [, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/locations', ['name' => 'Balcony', 'icon' => 'sun'])->assertRedirect();

    $loc = InventoryLocation::where('key', 'balcony')->firstOrFail();
    expect($loc->icon)->toBe('sun');

    actingAs($users[0])->post('/m/inventory/items', ['name' => 'Firewood', 'quantity' => 10, 'unit' => 'pieces', 'location' => 'balcony'])->assertRedirect();
    expect(InventoryItem::where('name', 'Firewood')->firstOrFail()->location)->toBe('balcony');
});

it('rejects a junk icon and a missing name when adding a category', function () {
    [, $users] = inventoryFamily();

    actingAs($users[0])->post('/m/inventory/categories', ['name' => 'X', 'icon' => 'not-a-real-icon'])->assertSessionHasErrors('icon');
    actingAs($users[0])->post('/m/inventory/categories', ['icon' => 'dog'])->assertSessionHasErrors('name');
});

it('blocks a non-admin from managing categories/locations', function () {
    [, $users] = inventoryFamily(2); // users[1] is a plain member

    actingAs($users[1])->post('/m/inventory/categories', ['name' => 'Pet'])->assertForbidden();
    actingAs($users[1])->post('/m/inventory/locations', ['name' => 'Attic'])->assertForbidden();

    // Reads stay open to members.
    actingAs($users[1])->get('/m/inventory/categories')->assertOk();
    actingAs($users[1])->get('/m/inventory/locations')->assertOk();
});

it('will not delete a category that is still in use, but deletes an empty one', function () {
    [, $users] = inventoryFamily();

    InventoryCategory::ensureDefaults();
    $used = InventoryCategory::create(['key' => 'pet', 'name' => 'Pet', 'icon' => 'dog', 'color' => '#6B7A99', 'is_default' => false, 'position' => 20]);
    $empty = InventoryCategory::create(['key' => 'garden', 'name' => 'Garden', 'icon' => 'flower', 'color' => '#7A9A80', 'is_default' => false, 'position' => 21]);
    makeItem(['name' => 'Kibble', 'category' => 'pet']);

    actingAs($users[0])->delete("/m/inventory/categories/{$used->id}")->assertSessionHasErrors('category');
    expect(InventoryCategory::find($used->id))->not->toBeNull(); // still there

    actingAs($users[0])->delete("/m/inventory/categories/{$empty->id}")->assertRedirect();
    expect(InventoryCategory::find($empty->id))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------
// V1.1 — categories & locations become fully editable: rename + re-icon + safe delete, for custom AND
// the seeded defaults (Bogdan runs two houses and wants to rename/re-organise Kitchen/Pantry/Food/…).
// The `key` never changes on an edit, so items keep resolving; renaming a default detaches it from its
// i18n label so the family's chosen name shows everywhere.
// ---------------------------------------------------------------------------------------------------

it('renames a custom category, keeping its stable key', function () {
    [, $users] = inventoryFamily();
    $cat = InventoryCategory::create(['key' => 'pet', 'name' => 'Pet', 'icon' => 'dog', 'color' => '#6B7A99', 'is_default' => false, 'position' => 20]);

    actingAs($users[0])->patch("/m/inventory/categories/{$cat->id}", ['name' => 'Pets & Animals'])->assertRedirect();

    $cat->refresh();
    expect($cat->name)->toBe('Pets & Animals')
        ->and($cat->key)->toBe('pet')            // key stable
        ->and($cat->is_default)->toBeFalse();
});

it('renames a seeded default category, detaching it from its localised label', function () {
    [, $users] = inventoryFamily();
    InventoryCategory::ensureDefaults();
    $food = InventoryCategory::where('key', 'food')->firstOrFail();
    expect($food->is_default)->toBeTrue();

    makeItem(['name' => 'Bread', 'category' => 'food']); // an item on the default, to prove it keeps resolving

    actingAs($users[0])->patch("/m/inventory/categories/{$food->id}", ['name' => 'Groceries'])->assertRedirect();

    $food->refresh();
    expect($food->name)->toBe('Groceries')
        ->and($food->is_default)->toBeFalse()    // a renamed default becomes the family's own -> uses name, not i18n
        ->and($food->key)->toBe('food');         // key stable, the item still points at `food`

    // Reflects on the categories screen (food is position 0).
    actingAs($users[0])->get('/m/inventory/categories')->assertInertia(
        fn ($p) => $p->where('categories.0.key', 'food')->where('categories.0.name', 'Groceries')->where('categories.0.isDefault', false)->etc()
    );
});

it('changes a default category icon without detaching its localised label', function () {
    [, $users] = inventoryFamily();
    InventoryCategory::ensureDefaults();
    $food = InventoryCategory::where('key', 'food')->firstOrFail();

    actingAs($users[0])->patch("/m/inventory/categories/{$food->id}", ['icon' => 'cat'])->assertRedirect();

    $food->refresh();
    expect($food->icon)->toBe('cat')
        ->and($food->is_default)->toBeTrue()     // an icon-only edit keeps the default localised
        ->and($food->name)->toBe('Food');
});

it('validates a category rename (name required, max 40)', function () {
    [, $users] = inventoryFamily();
    InventoryCategory::ensureDefaults();
    $food = InventoryCategory::where('key', 'food')->firstOrFail();

    actingAs($users[0])->patch("/m/inventory/categories/{$food->id}", ['name' => ''])->assertSessionHasErrors('name');
    actingAs($users[0])->patch("/m/inventory/categories/{$food->id}", ['name' => str_repeat('a', 41)])->assertSessionHasErrors('name');
});

it('renames and re-icons a seeded default location, and it reflects on the items screen', function () {
    [, $users] = inventoryFamily();
    InventoryLocation::ensureDefaults();
    $kitchen = InventoryLocation::where('key', 'kitchen')->firstOrFail(); // position 0
    expect($kitchen->is_default)->toBeTrue();
    makeItem(['name' => 'Pan', 'location' => 'kitchen']);

    actingAs($users[0])->patch("/m/inventory/locations/{$kitchen->id}", ['name' => 'Countryside Kitchen', 'icon' => 'house'])->assertRedirect();

    $kitchen->refresh();
    expect($kitchen->name)->toBe('Countryside Kitchen')
        ->and($kitchen->icon)->toBe('house')
        ->and($kitchen->is_default)->toBeFalse()
        ->and($kitchen->key)->toBe('kitchen');   // item still points at `kitchen`

    // The renamed default carries its new name + isDefault:false everywhere the vocabulary is shipped.
    actingAs($users[0])->get('/m/inventory/items')->assertInertia(
        fn ($p) => $p->where('locations.0.key', 'kitchen')->where('locations.0.name', 'Countryside Kitchen')->where('locations.0.isDefault', false)->etc()
    );
});

it('blocks deleting a location in use (with the item count) and allows an empty default', function () {
    [, $users] = inventoryFamily();
    InventoryLocation::ensureDefaults();
    $pantry = InventoryLocation::where('key', 'pantry')->firstOrFail(); // makeItem defaults to pantry
    $garage = InventoryLocation::where('key', 'garage')->firstOrFail(); // empty seeded default
    makeItem(['name' => 'Flour', 'location' => 'pantry']);

    actingAs($users[0])->delete("/m/inventory/locations/{$pantry->id}")->assertSessionHasErrors('location');
    expect(InventoryLocation::find($pantry->id))->not->toBeNull(); // in use -> kept, item never orphaned

    actingAs($users[0])->delete("/m/inventory/locations/{$garage->id}")->assertRedirect();
    expect(InventoryLocation::find($garage->id))->toBeNull();      // empty seeded default deletes fine
});

it('blocks a non-admin from renaming or deleting a category/location', function () {
    [, $users] = inventoryFamily(2); // users[1] is a plain member
    InventoryCategory::ensureDefaults();
    InventoryLocation::ensureDefaults();
    $food = InventoryCategory::where('key', 'food')->firstOrFail();
    $kitchen = InventoryLocation::where('key', 'kitchen')->firstOrFail();

    actingAs($users[1])->patch("/m/inventory/categories/{$food->id}", ['name' => 'X'])->assertForbidden();
    actingAs($users[1])->delete("/m/inventory/categories/{$food->id}")->assertForbidden();
    actingAs($users[1])->patch("/m/inventory/locations/{$kitchen->id}", ['name' => 'X'])->assertForbidden();
    actingAs($users[1])->delete("/m/inventory/locations/{$kitchen->id}")->assertForbidden();

    expect($food->fresh()->name)->toBe('Food');
});

it('will not let an admin manage another family\'s category', function () {
    [$familyA, $usersA] = inventoryFamily();

    $userB = User::factory()->create();
    $familyB = Family::factory()->create(['owner_id' => $userB->id]);
    $familyB->members()->attach($userB, ['role' => 'admin']);
    CurrentFamily::set($familyB->id);
    app(ModuleRegistry::class)->enable('inventory', $familyB->id);
    $catB = InventoryCategory::create(['key' => 'pet', 'name' => 'Pet', 'icon' => 'dog', 'color' => '#6B7A99', 'is_default' => false, 'position' => 20, 'family_id' => $familyB->id]);

    session(['active_family_id' => $familyA->id]);
    CurrentFamily::set($familyA->id);

    actingAs($usersA[0])->patch("/m/inventory/categories/{$catB->id}", ['icon' => 'cat'])->assertNotFound();
    actingAs($usersA[0])->delete("/m/inventory/categories/{$catB->id}")->assertNotFound();
    expect(InventoryCategory::withoutGlobalScopes()->find($catB->id)->icon)->toBe('dog');
});

// ---------------------------------------------------------------------------------------------------
// Phase 4 — the Inventory <-> Shopping core loop. These exercise the whole cycle end to end: a low
// item is surfaced onto a real shopping list, buying it there replenishes the stock, and the link is
// cleared so it can go round again. Shopping must be enabled too (both modules migrate together).
// ---------------------------------------------------------------------------------------------------

// Enable the shopping module for the already-active family (inventoryFamily() only turns on inventory).
function enableShopping(Family $family): void
{
    app(ModuleRegistry::class)->enable('shopping', $family->id);
}

it('adds a low item to Shopping exactly once (idempotent)', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $item = makeItem(['name' => 'Milk', 'quantity' => 1, 'unit' => 'L', 'min_quantity' => 2, 'target_quantity' => 4]);
    expect($item->state())->toBe('low');

    // First push: creates one shopping row and links it.
    actingAs($users[0])->post("/m/inventory/items/{$item->id}/shopping")->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);
    $shopItem = ShoppingItem::withoutGlobalScopes()->first();
    expect($shopItem->name)->toBe('Milk')
        ->and($shopItem->quantity)->toBe('to 4 L')            // target-informed label
        ->and($item->fresh()->shopping_item_id)->toBe($shopItem->id);

    // Second push while it is still open: no duplicate.
    actingAs($users[0])->post("/m/inventory/items/{$item->id}/shopping")->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);
});

it('bulk-adds every distinct low/empty item once, skipping already-added and auto-off items', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $low = makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 3]);
    $empty = makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);
    makeItem(['name' => 'Flour', 'quantity' => 1, 'min_quantity' => 3, 'auto_replenish' => false]); // auto off => skipped
    makeItem(['name' => 'Sugar', 'quantity' => 9, 'min_quantity' => 3]);                            // good => skipped

    actingAs($users[0])->post('/m/inventory/shopping/add-low')->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2)
        ->and(ShoppingItem::withoutGlobalScopes()->pluck('name')->sort()->values()->all())->toBe(['Eggs', 'Rice']);
    expect($low->fresh()->shopping_item_id)->not->toBeNull()
        ->and($empty->fresh()->shopping_item_id)->not->toBeNull();

    // Running it again adds nothing new (all remaining candidates are already on a list).
    actingAs($users[0])->post('/m/inventory/shopping/add-low')->assertRedirect();
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(2);
});

it('replenishes the inventory item when its linked shopping item is bought', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $item = makeItem(['name' => 'Parmesan', 'quantity' => 80, 'unit' => 'g', 'min_quantity' => 100, 'target_quantity' => 500]);
    expect($item->state())->toBe('low');

    actingAs($users[0])->post("/m/inventory/items/{$item->id}/shopping")->assertRedirect();
    $shopItem = ShoppingItem::withoutGlobalScopes()->first();

    // Mark it bought via the real Shopping endpoint (empty body toggles bought) — fires the hook.
    actingAs($users[0])->patch("/m/shopping/items/{$shopItem->id}")->assertRedirect();

    $fresh = $item->fresh();
    expect($fresh->quantity)->toEqual(500.0)          // topped up to target
        ->and($fresh->shopping_item_id)->toBeNull()    // link cleared -> can be replenished again
        ->and($fresh->state())->toBe('good');          // left the low state
    // A "purchased" movement was logged with the signed delta (+420).
    $log = InventoryLog::withoutGlobalScopes()->where('inventory_item_id', $item->id)->where('type', 'purchased')->first();
    expect($log)->not->toBeNull()->and((float) $log->delta)->toEqual(420.0);
});

it('falls back to 2x minimum when a bought item has no target', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $item = makeItem(['name' => 'Sponges', 'quantity' => 0, 'min_quantity' => 4]); // empty, no target
    actingAs($users[0])->post("/m/inventory/items/{$item->id}/shopping")->assertRedirect();
    $shopItem = ShoppingItem::withoutGlobalScopes()->first();

    actingAs($users[0])->patch("/m/shopping/items/{$shopItem->id}")->assertRedirect();
    expect($item->fresh()->quantity)->toEqual(8.0)   // 2 x min => "full", leaves the empty state
        ->and($item->fresh()->state())->toBe('good');
});

it('does nothing when an unlinked shopping item is bought', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $item = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2, 'target_quantity' => 4]);
    // A plain grocery line, never linked to any inventory item.
    $list = ShoppingList::defaultFor($users[0]);
    $loose = $list->items()->create(['name' => 'Bananas', 'created_by' => $users[0]->id]);

    actingAs($users[0])->patch("/m/shopping/items/{$loose->id}")->assertRedirect();
    expect($item->fresh()->quantity)->toEqual(1.0)   // untouched
        ->and(InventoryLog::withoutGlobalScopes()->where('type', 'purchased')->count())->toBe(0);
});

it('re-buying an already-bought linked item does not replenish twice', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $item = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2, 'target_quantity' => 4]);
    actingAs($users[0])->post("/m/inventory/items/{$item->id}/shopping")->assertRedirect();
    $shopItem = ShoppingItem::withoutGlobalScopes()->first();

    actingAs($users[0])->patch("/m/shopping/items/{$shopItem->id}")->assertRedirect(); // bought -> replenish
    expect($item->fresh()->quantity)->toEqual(4.0);

    // Toggle back to unbought, then bought again. The inventory item is no longer linked, so nothing happens.
    actingAs($users[0])->patch("/m/shopping/items/{$shopItem->id}")->assertRedirect(); // -> unbought
    actingAs($users[0])->patch("/m/shopping/items/{$shopItem->id}")->assertRedirect(); // -> bought again
    expect($item->fresh()->quantity)->toEqual(4.0)   // still target, not doubled
        ->and(InventoryLog::withoutGlobalScopes()->where('inventory_item_id', $item->id)->where('type', 'purchased')->count())->toBe(1);
});

it('surfaces only low/empty auto-replenish items on the replenishment screen', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 3]);                              // low, auto on
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);                              // empty, auto on
    makeItem(['name' => 'Flour', 'quantity' => 1, 'min_quantity' => 3, 'auto_replenish' => false]);  // low, auto off
    makeItem(['name' => 'Sugar', 'quantity' => 9, 'min_quantity' => 3]);                             // good

    actingAs($users[0])->get('/m/inventory/replenishment')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/replenishment')
            ->has('items', 2)               // Rice + Eggs only
            ->where('pending', 2)
            ->where('items.0.replenishTo', 12) // Eggs first (empty), 2x min 6
            ->etc()
    );
});

it('scopes the loop to the acting family', function () {
    [$familyA, $usersA] = inventoryFamily();
    enableShopping($familyA);
    $itemA = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2, 'target_quantity' => 4]);
    actingAs($usersA[0])->post("/m/inventory/items/{$itemA->id}/shopping")->assertRedirect();
    $shopA = ShoppingItem::withoutGlobalScopes()->first();

    // A second family can't even resolve family A's shopping item (the BelongsToFamily scope hides it ->
    // 404), so the loop never fires cross-family.
    $userB = User::factory()->create();
    $familyB = Family::factory()->create(['owner_id' => $userB->id]);
    $familyB->members()->attach($userB, ['role' => 'admin']);
    session(['active_family_id' => $familyB->id]);
    CurrentFamily::set($familyB->id);
    enableShopping($familyB);

    actingAs($userB)->patch("/m/shopping/items/{$shopA->id}")->assertNotFound();
    expect($itemA->fresh()->quantity)->toEqual(1.0)          // untouched
        ->and($itemA->fresh()->shopping_item_id)->toBe($shopA->id);
});

// The dedicated "Inventory" shopping list + per-item shop (Inventory -> Shopping targeting).

it('surfaces items onto one dedicated Inventory list and reuses it every time', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    // The acting user's own latest list must NOT be the target any more.
    $mine = ShoppingList::create(['name' => 'Weekend', 'created_by' => $users[0]->id]);
    $milk = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);
    $eggs = makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);

    actingAs($users[0])->post("/m/inventory/items/{$milk->id}/shopping")->assertRedirect();
    actingAs($users[0])->post("/m/inventory/items/{$eggs->id}/shopping")->assertRedirect();

    $lists = ShoppingList::withoutGlobalScopes()->where('name', 'Inventory')->get();
    expect($lists)->toHaveCount(1);
    $list = $lists->first();
    expect(ShoppingItem::withoutGlobalScopes()->where('shopping_list_id', $list->id)->count())->toBe(2)
        ->and($mine->items()->count())->toBe(0)
        ->and(InventorySetting::current()->shopping_list_id)->toBe($list->id)
        ->and($list->visibility())->toBe('family');

    // Even with the remembered pointer gone, the live list is adopted by name — never duplicated.
    InventorySetting::current()->forceFill(['shopping_list_id' => null])->save();
    $rice = makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 3]);
    actingAs($users[0])->post("/m/inventory/items/{$rice->id}/shopping")->assertRedirect();
    expect(ShoppingList::withoutGlobalScopes()->where('name', 'Inventory')->count())->toBe(1)
        ->and(InventorySetting::current()->shopping_list_id)->toBe($list->id);

    // Archiving it makes the next add mint a fresh live one (the archived list is left alone).
    $list->update(['archived_at' => now()]);
    $soap = makeItem(['name' => 'Soap', 'quantity' => 0, 'min_quantity' => 1]);
    actingAs($users[0])->post("/m/inventory/items/{$soap->id}/shopping")->assertRedirect();
    expect(ShoppingList::withoutGlobalScopes()->where('name', 'Inventory')->whereNull('archived_at')->count())->toBe(1)
        ->and(InventorySetting::current()->shopping_list_id)->not->toBe($list->id);
});

it('carries the item shop onto the shopping row and leaves it empty when none is chosen', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $milk = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2, 'shop' => 'Lidl']);
    $eggs = makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);

    actingAs($users[0])->post('/m/inventory/shopping/add-low')->assertRedirect();

    $rows = ShoppingItem::withoutGlobalScopes()->get()->keyBy('name');
    expect($rows['Milk']->store)->toBe('Lidl')
        ->and($rows['Eggs']->store)->toBeNull()
        ->and($rows['Milk']->shopping_list_id)->toBe($rows['Eggs']->shopping_list_id)
        ->and(ShoppingShop::withoutGlobalScopes()->where('name', 'Lidl')->exists())->toBeTrue(); // Shops view stays complete
    expect($milk->fresh()->shopping_item_id)->toBe($rows['Milk']->id);

    // Buying the shopped row still closes the loop (reverse half unchanged).
    actingAs($users[0])->patch("/m/shopping/items/{$rows['Milk']->id}")->assertRedirect();
    expect($milk->fresh()->quantity)->toEqual(4.0)->and($milk->fresh()->shopping_item_id)->toBeNull();
});

it('stores a chosen shop on create and edit and ensures the shop row exists', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);

    actingAs($users[0])->post('/m/inventory/items', ['name' => 'Butter', 'unit' => 'g', 'quantity' => 200, 'shop' => ' Kaufland '])->assertRedirect();
    $item = InventoryItem::first();
    expect($item->shop)->toBe('Kaufland')
        ->and(ShoppingShop::where('name', 'Kaufland')->exists())->toBeTrue();

    actingAs($users[0])->patch("/m/inventory/items/{$item->id}", ['name' => 'Butter', 'unit' => 'g', 'quantity' => 200, 'shop' => null])->assertRedirect();
    expect($item->fresh()->shop)->toBeNull();

    actingAs($users[0])->get('/m/inventory/items')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/items')->where('shops', ['Kaufland'])->where('items.0.shop', null)->etc()
    );
});

it('gives each family its own dedicated Inventory list', function () {
    [$familyA, $usersA] = inventoryFamily();
    enableShopping($familyA);
    $a = makeItem(['name' => 'Milk', 'quantity' => 0, 'min_quantity' => 2]);
    actingAs($usersA[0])->post("/m/inventory/items/{$a->id}/shopping")->assertRedirect();

    [$familyB, $usersB] = inventoryFamily();
    enableShopping($familyB);
    $b = makeItem(['name' => 'Milk', 'quantity' => 0, 'min_quantity' => 2]);
    actingAs($usersB[0])->post("/m/inventory/items/{$b->id}/shopping")->assertRedirect();

    $lists = ShoppingList::withoutGlobalScopes()->where('name', 'Inventory')->get();
    expect($lists)->toHaveCount(2)
        ->and($lists->pluck('family_id')->sort()->values()->all())->toBe([$familyA->id, $familyB->id])
        ->and(InventorySetting::withoutGlobalScopes()->where('family_id', $familyA->id)->value('shopping_list_id'))->toBe($lists->firstWhere('family_id', $familyA->id)->id)
        ->and(InventorySetting::withoutGlobalScopes()->where('family_id', $familyB->id)->value('shopping_list_id'))->toBe($lists->firstWhere('family_id', $familyB->id)->id);
});

// ---------------------------------------------------------------------------------------------------
// Phase 5 — Settings, the daily inventory:check (notifications + auto-replenish), and the dashboard
// widget feed.
// ---------------------------------------------------------------------------------------------------

it('renders inventory settings with calm defaults and the admin flag', function () {
    [, $users] = inventoryFamily();

    actingAs($users[0])->get('/m/inventory/settings')->assertOk()->assertInertia(
        fn ($p) => $p->component('inventory/settings')
            ->where('settings.unitSystem', 'metric')
            ->where('settings.autoAddLow', true)
            ->where('settings.notifyStale', true)
            ->where('canManage', true)
            ->has('unitSystems')
            ->etc()
    );
});

it('lets an admin change settings and persists only the fields sent', function () {
    [$family, $users] = inventoryFamily();

    // A partial patch (one toggle) must not clobber the other fields.
    actingAs($users[0])->patch('/m/inventory/settings', ['notify_stale' => false])->assertRedirect();
    $s = InventorySetting::current($family->id);
    expect($s->notify_stale)->toBeFalse()
        ->and($s->notify_low)->toBeTrue()          // untouched
        ->and($s->auto_add_low)->toBeTrue();       // untouched

    actingAs($users[0])->patch('/m/inventory/settings', ['unit_system' => 'imperial', 'auto_add_empty' => false])->assertRedirect();
    $s = InventorySetting::current($family->id)->fresh();
    expect($s->unit_system)->toBe('imperial')
        ->and($s->auto_add_empty)->toBeFalse()
        ->and($s->notify_stale)->toBeFalse();      // still off from the earlier patch
});

it('rejects an unknown unit system and blocks a non-admin from writing settings', function () {
    [, $users] = inventoryFamily(2); // users[1] is a plain member

    actingAs($users[0])->patch('/m/inventory/settings', ['unit_system' => 'furlongs'])->assertSessionHasErrors('unit_system');

    // Members can read the screen but not write.
    actingAs($users[1])->get('/m/inventory/settings')->assertOk()
        ->assertInertia(fn ($p) => $p->where('canManage', false)->etc());
    actingAs($users[1])->patch('/m/inventory/settings', ['notify_low' => false])->assertForbidden();
});

it('inventory:check notifies once per condition and does not double-fire the same day', function () {
    Notification::fake();
    [, $users] = inventoryFamily();

    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);                    // good — no alert
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);                    // low
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);                    // empty
    makeItem(['name' => 'Ham', 'quantity' => 2, 'expires_at' => now()->subDay()]);         // expired
    $stale = makeItem(['name' => 'Vinegar', 'quantity' => 5, 'min_quantity' => 1]);        // good…
    ageItem($stale, 60);                                                                    // …but stale

    test()->artisan('inventory:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 4);       // low + empty + expired + stale (good is silent)
    expect(InventoryAlert::withoutGlobalScopes()->count())->toBe(4);

    // Same-day re-run: idempotent, no new pushes.
    test()->artisan('inventory:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 4);
});

it('inventory:check respects the family notification toggles', function () {
    Notification::fake();
    [$family, $users] = inventoryFamily();
    InventorySetting::current($family->id)->update(['notify_low' => false, 'notify_stale' => false]);

    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);                    // low — muted
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);                    // empty — on
    $stale = makeItem(['name' => 'Vinegar', 'quantity' => 5, 'min_quantity' => 1]);
    ageItem($stale, 60);                                                                    // stale — muted

    test()->artisan('inventory:check')->assertExitCode(0);
    Notification::assertSentTimes(PushAlert::class, 1);       // only the empty item
});

it('inventory:check re-notifies after a condition clears and returns', function () {
    Notification::fake();
    [, $users] = inventoryFamily();
    $milk = makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);            // low

    test()->artisan('inventory:check');
    Notification::assertSentTimes(PushAlert::class, 1);

    // Restock: the low condition clears, so its alert row is pruned.
    $milk->update(['quantity' => 5]);
    test()->artisan('inventory:check');
    expect(InventoryAlert::withoutGlobalScopes()->where('inventory_item_id', $milk->id)->count())->toBe(0);

    // Runs low again → notifies fresh, because clearing pruned the old alert row (not suppressed by
    // the same-day guard, which only blocks a condition that stayed continuously true).
    $milk->update(['quantity' => 1]);
    test()->artisan('inventory:check');
    Notification::assertSentTimes(PushAlert::class, 2);
});

it('inventory:check auto-adds short stock to Shopping only when the family opted in', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    $low = makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 3]);             // low, auto on
    makeItem(['name' => 'Flour', 'quantity' => 1, 'min_quantity' => 3, 'auto_replenish' => false]); // auto off

    // Defaults have auto_add_low ON → the low, auto-replenish item is surfaced once.
    test()->artisan('inventory:check')->assertExitCode(0);
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1)
        ->and($low->fresh()->shopping_item_id)->not->toBeNull();

    // Re-running is idempotent (already linked).
    test()->artisan('inventory:check');
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(1);
});

it('inventory:check does not auto-add when both replenishment toggles are off', function () {
    [$family, $users] = inventoryFamily();
    enableShopping($family);
    InventorySetting::current($family->id)->update(['auto_add_low' => false, 'auto_add_empty' => false]);
    makeItem(['name' => 'Rice', 'quantity' => 1, 'min_quantity' => 3]);                    // low

    test()->artisan('inventory:check')->assertExitCode(0);
    expect(ShoppingItem::withoutGlobalScopes()->count())->toBe(0);
});

it('inventory:check skips a family that has the module disabled', function () {
    Notification::fake();
    [$family, $users] = inventoryFamily();
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);                    // low

    app(ModuleRegistry::class)->disable('inventory', $family->id);
    test()->artisan('inventory:check')->assertExitCode(0);
    Notification::assertNothingSent();
});

it('serves the dashboard widget feed: counts + a few exceptions, most urgent first', function () {
    [, $users] = inventoryFamily();
    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);                    // good
    makeItem(['name' => 'Milk', 'quantity' => 1, 'min_quantity' => 2]);                    // low
    makeItem(['name' => 'Eggs', 'quantity' => 0, 'min_quantity' => 6]);                    // empty
    makeItem(['name' => 'Ham', 'quantity' => 2, 'expires_at' => now()->subDay()]);         // expired
    makeItem(['name' => 'Salt', 'quantity' => null]);                                      // unknown — not an exception

    actingAs($users[0])->getJson('/m/inventory/widget')->assertOk()->assertJson([
        'total' => 5,
        'low' => 1,
        'empty' => 1,
    ])->assertJsonCount(3, 'items')                     // low + empty + expired only (good/unknown excluded)
        ->assertJsonPath('items.0.state', 'expired');   // most urgent first
});

it('returns no widget exceptions when nothing needs attention, and 404s when disabled', function () {
    [$family, $users] = inventoryFamily();
    makeItem(['name' => 'Rice', 'quantity' => 8, 'min_quantity' => 2]);                    // good only

    actingAs($users[0])->getJson('/m/inventory/widget')->assertOk()
        ->assertJson(['total' => 1, 'low' => 0, 'empty' => 0])
        ->assertJsonCount(0, 'items');

    app(ModuleRegistry::class)->disable('inventory', $family->id);
    actingAs($users[0])->getJson('/m/inventory/widget')->assertNotFound();
});
