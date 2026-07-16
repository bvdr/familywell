<?php

namespace Modules\Shopping;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShoppingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'module:shopping'])->group(function () {
            Route::get('/m/shopping/items', [ShoppingController::class, 'index'])->name('shopping.index');
            Route::post('/m/shopping/items', [ShoppingController::class, 'store'])->name('shopping.store');
            Route::patch('/m/shopping/items/{item}', [ShoppingController::class, 'update'])->name('shopping.update');
            Route::delete('/m/shopping/items/{item}', [ShoppingController::class, 'destroy'])->name('shopping.destroy');
        });
    }
}
