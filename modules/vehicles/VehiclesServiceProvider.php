<?php

namespace Modules\Vehicles;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class VehiclesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Full Inertia pages, gated by `module:vehicles` so they only exist for families that enabled
        // it. Vehicles are family-wide shared config (every member sees and may edit the same cars, like
        // inventory stock or the shopping aisles), so there is no per-vehicle sharing to register — the
        // BelongsToFamily scope IS the sharing boundary. owner/primary_driver are metadata references,
        // not permission gates. Maintenance / repairs / fuel / documents / notifications / dashboard
        // widget arrive in later phases; P1 is Cars + first-class Mileage only.
        Route::middleware(['web', 'auth', 'family', 'module:vehicles'])
            ->prefix('m/vehicles')
            ->name('vehicles.')
            ->group(function () {
                // My Cars + the add/edit form. `new`/`{vehicle}/edit` render the same full-page form.
                Route::get('/', [VehiclesController::class, 'index'])->name('index');
                Route::get('/new', [VehiclesController::class, 'create'])->name('create');
                Route::post('/', [VehiclesController::class, 'store'])->name('store');

                // Per-car Overview + edit + delete.
                Route::get('/{vehicle}', [VehiclesController::class, 'show'])->name('show');
                Route::get('/{vehicle}/edit', [VehiclesController::class, 'edit'])->name('edit');
                Route::patch('/{vehicle}', [VehiclesController::class, 'update'])->name('update');
                Route::delete('/{vehicle}', [VehiclesController::class, 'destroy'])->name('destroy');

                // Mileage: the daily-use path. Takes the new absolute odometer reading (after a quick
                // preset or manual entry), logs it and updates current_mileage. Its own endpoint so the
                // common "I drove some km" action stays a single, fast round-trip separate from a full edit.
                Route::post('/{vehicle}/mileage', [VehiclesController::class, 'updateMileage'])->name('mileage');

                // Optional cover photo (R2, same StoredImage pattern as project covers / spend receipts).
                Route::post('/{vehicle}/photo', [VehiclesController::class, 'uploadPhoto'])->name('photo.upload');
                Route::get('/{vehicle}/photo', [VehiclesController::class, 'photoFile'])->name('photo.show');
                Route::delete('/{vehicle}/photo', [VehiclesController::class, 'deletePhoto'])->name('photo.delete');
            });
    }
}
