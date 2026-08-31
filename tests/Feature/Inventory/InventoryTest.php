<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Inventory\InventoryCategory;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryLocation;
use Modules\Inventory\InventoryLog;

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
