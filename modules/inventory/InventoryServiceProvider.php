<?php

namespace Modules\Inventory;

use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Console\CheckInventory;
use Modules\Notifications\NotificationCategories;
use Modules\Shopping\ShoppingItem;

class InventoryServiceProvider extends ServiceProvider
{
    /** Register the module's console command so `inventory:check` is available to artisan + the scheduler. */
    public function register(): void
    {
        $this->commands([CheckInventory::class]);
    }

    public function boot(Hooks $hooks): void
    {
        // Close the Shopping -> Inventory half of the loop: when a shopping item the family bought is one
        // we surfaced from inventory, restock the linked item (see InventoryReplenishment). We listen on the
        // shared hooks bus rather than editing the Shopping controller — Shopping just publishes the event,
        // exactly as Tasks publishes task.saved. The listener is a no-op for ordinary (unlinked) grocery lines.
        $hooks->listen('shopping.item.bought', function (ShoppingItem $item) {
            app(InventoryReplenishment::class)->onShoppingItemBought($item);
        });

        // MCP: tools an AI assistant can use on the family's stock, gated by the `inventory:read` /
        // `inventory:write` scopes granted on the consent screen (see docs/mcp.md).
        // Delete tools are permanent (these records have no trash), so they are marked destructive and ask the
        // assistant to confirm with the person first.
        McpTools::register('inventory', 'Inventory', [
            Mcp\ListItems::class, Mcp\GetItem::class, Mcp\NeedsAttention::class,
            Mcp\AddItem::class, Mcp\UpdateItem::class, Mcp\AdjustQuantity::class, Mcp\AddToShopping::class, Mcp\DeleteItem::class, ]);

        // Our notification category, so inventory alerts show in the feed and get their own per-user
        // on/off row on the Notifications settings screen. Registration is idempotent (boot re-runs per
        // request/worker); the family-level condition toggles live separately in InventorySetting.
        NotificationCategories::register('inventory', 'Inventory', 'package');

        // The daily sweep: notify about low/empty/expired/stale stock and auto-add short items to Shopping
        // for families that opted in. Registered the same way recurring-todos:tick is — each module wires
        // its own schedule via the resolved Schedule. NOTE: the scheduler worker may not be live in prod
        // yet; the command is fully runnable by hand (`php artisan inventory:check`) regardless.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('inventory:check')->dailyAt('07:00')->withoutOverlapping();
        });

        // Full Inertia pages, gated by `module:inventory` so they only exist for families that enabled
        // it. Inventory is family-wide shared config (every member sees the same stock, like the
        // shopping aisles or spending categories), so there is no per-item sharing to register — the
        // BelongsToFamily scope IS the sharing boundary.
        Route::middleware(['web', 'auth', 'family', 'module:inventory'])
            ->prefix('m/inventory')
            ->name('inventory.')
            ->group(function () {
                Route::get('/', [InventoryController::class, 'index'])->name('index');
                Route::get('/items', [InventoryController::class, 'items'])->name('items');
                Route::get('/items/{item}', [InventoryController::class, 'show'])->name('items.show');

                // Phase 3 focused screens: triage (Needs Attention) + the category/location browse &
                // management screens. All read-open to members.
                Route::get('/needs-attention', [InventoryController::class, 'needsAttention'])->name('needs-attention');
                Route::get('/categories', [InventoryController::class, 'categories'])->name('categories');
                Route::get('/locations', [InventoryController::class, 'locations'])->name('locations');

                // Phase 5: module settings (unit system, replenishment + notification policy) and the
                // JSON feed the home-screen widget reads. Settings reads are open to members; the write
                // authorizes admin inside the controller (like the Spending settings).
                Route::get('/settings', [InventoryController::class, 'settings'])->name('settings');
                Route::patch('/settings', [InventoryController::class, 'updateSettings'])->name('settings.update');
                Route::get('/widget', [InventoryController::class, 'widget'])->name('widget');

                // Phase 4: the Inventory -> Shopping loop. The replenishment screen, the per-item "Add to
                // Shopping" push, and the bulk "add all low items" sweep. Open to any member (like the other
                // inventory writes); idempotency lives in InventoryReplenishment, not the routes.
                Route::get('/replenishment', [InventoryController::class, 'replenishment'])->name('replenishment');
                Route::post('/items/{item}/shopping', [InventoryController::class, 'addToShopping'])->name('items.shopping');
                Route::post('/shopping/add-low', [InventoryController::class, 'addLowToShopping'])->name('shopping.add-low');

                // Writes. Any family member may edit stock (inventory is collaborative; the family scope
                // is the boundary). Quick-Update is its own endpoint so the daily "we used the milk"
                // action stays a single, fast round-trip separate from a full edit.
                Route::post('/items', [InventoryController::class, 'store'])->name('items.store');
                Route::patch('/items/{item}', [InventoryController::class, 'update'])->name('items.update');
                Route::delete('/items/{item}', [InventoryController::class, 'destroy'])->name('items.destroy');
                Route::post('/items/{item}/adjust', [InventoryController::class, 'adjust'])->name('items.adjust');

                // Category/location management. Any member can mint a category/location inline while
                // adding an item; these explicit endpoints (add with a chosen icon, change icon, delete)
                // are family config and admin-gated in the controller, like the Spending category settings.
                Route::post('/categories', [InventoryController::class, 'storeCategory'])->name('categories.store');
                Route::patch('/categories/{category}', [InventoryController::class, 'updateCategory'])->name('categories.update');
                Route::delete('/categories/{category}', [InventoryController::class, 'destroyCategory'])->name('categories.destroy');
                Route::post('/locations', [InventoryController::class, 'storeLocation'])->name('locations.store');
                Route::patch('/locations/{location}', [InventoryController::class, 'updateLocation'])->name('locations.update');
                Route::delete('/locations/{location}', [InventoryController::class, 'destroyLocation'])->name('locations.destroy');
            });
    }
}
