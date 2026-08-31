<?php

namespace Modules\Inventory;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Full Inertia pages, gated by `module:inventory` so they only exist for families that enabled
        // it. Inventory is family-wide shared config (every member sees the same stock, like the
        // shopping aisles or spending categories), so there is no per-item sharing to register — the
        // BelongsToFamily scope IS the sharing boundary. P2 adds the write flows (Add/Edit/Quick-Update
        // + history log); the Shopping loop (P4) and dashboard widget (P5) land in later phases.
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
