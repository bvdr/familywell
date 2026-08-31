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
        // BelongsToFamily scope IS the sharing boundary. Writes (P2) and the dashboard widget (P5) land
        // in later phases; P1 is read-only.
        Route::middleware(['web', 'auth', 'family', 'module:inventory'])
            ->prefix('m/inventory')
            ->name('inventory.')
            ->group(function () {
                Route::get('/', [InventoryController::class, 'index'])->name('index');
                Route::get('/items', [InventoryController::class, 'items'])->name('items');
                Route::get('/items/{item}', [InventoryController::class, 'show'])->name('items.show');
            });
    }
}
