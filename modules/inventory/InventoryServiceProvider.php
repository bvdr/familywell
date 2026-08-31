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

                // Writes. Any family member may edit stock (inventory is collaborative; the family scope
                // is the boundary). Quick-Update is its own endpoint so the daily "we used the milk"
                // action stays a single, fast round-trip separate from a full edit.
                Route::post('/items', [InventoryController::class, 'store'])->name('items.store');
                Route::patch('/items/{item}', [InventoryController::class, 'update'])->name('items.update');
                Route::delete('/items/{item}', [InventoryController::class, 'destroy'])->name('items.destroy');
                Route::post('/items/{item}/adjust', [InventoryController::class, 'adjust'])->name('items.adjust');
            });
    }
}
