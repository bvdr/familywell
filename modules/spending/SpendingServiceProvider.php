<?php

namespace Modules\Spending;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SpendingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Full Inertia pages (not a dashboard widget): Spending is a whole app section. Gated by
        // `module:spending` so it is only reachable when the family has enabled it.
        Route::middleware(['web', 'auth', 'family', 'module:spending'])
            ->prefix('m/spending')
            ->name('spending.')
            ->group(function () {
                Route::get('/', [SpendingController::class, 'index'])->name('index');
                Route::post('/events', [SpendingController::class, 'storeEvent'])->name('events.store');
                Route::get('/events/{event}', [SpendingController::class, 'show'])->name('show');
                Route::post('/events/{event}/spends', [SpendingController::class, 'storeSpend'])->name('spends.store');
                Route::post('/events/{event}/settle', [SpendingController::class, 'settle'])->name('settle');
                Route::patch('/spends/{spend}', [SpendingController::class, 'updateSpend'])->name('spends.update');
                Route::post('/spends/{spend}/duplicate', [SpendingController::class, 'duplicateSpend'])->name('spends.duplicate');
                Route::get('/spends/{spend}/receipt', [SpendingController::class, 'receipt'])->name('spends.receipt');
                Route::delete('/spends/{spend}', [SpendingController::class, 'destroySpend'])->name('spends.destroy');
            });
    }
}
