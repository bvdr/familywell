<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Inventory\InventoryItem;
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
