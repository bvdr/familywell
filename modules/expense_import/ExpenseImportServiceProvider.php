<?php

namespace Modules\ExpenseImport;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Expense import (#215): card spending into Spending, reviewed before it's saved. Two ways in: a bank
 * statement upload (CSV/XLSX) and an Apple Pay link for an iPhone Shortcut. Kept out of Spending on purpose:
 * it writes through Spending's own create path (see Importer) and can be switched off per family. Everything
 * is turned on and configured on the module's settings page (Settings > Modules > Expense import).
 */
class ExpenseImportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'family', 'module:expense_import'])
            ->prefix('m/expense-import')
            ->name('expense_import.')
            ->group(function () {
                Route::get('/', [ExpenseImportController::class, 'index'])->name('index');
                // Reads are open to members; the switches/defaults are admin-only inside the controller.
                Route::get('/settings', [ExpenseImportController::class, 'settings'])->name('settings');
                Route::patch('/settings', [ExpenseImportController::class, 'updateSettings'])->name('settings.update');
                // A personal link that writes into the family: not for the public demo account (like share links).
                Route::post('/settings/token', [ExpenseImportController::class, 'createToken'])->middleware('not-demo:token')->name('token.create');
                Route::delete('/settings/token', [ExpenseImportController::class, 'revokeToken'])->name('token.revoke');
                Route::post('/statements', [ExpenseImportController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
                Route::get('/batches/{batch}', [ExpenseImportController::class, 'show'])->whereNumber('batch')->name('batch');
                Route::get('/batches/{batch}/map', [ExpenseImportController::class, 'map'])->whereNumber('batch')->name('map');
                Route::post('/batches/{batch}/map', [ExpenseImportController::class, 'saveMapping'])->whereNumber('batch')->name('map.save');
                // Each call is up to 5 paid AI calls: throttled like Spending's own suggestion endpoint.
                Route::post('/batches/{batch}/suggest', [ExpenseImportController::class, 'suggest'])->whereNumber('batch')->middleware('throttle:60,1')->name('suggest');
                Route::post('/batches/{batch}/import', [ExpenseImportController::class, 'import'])->whereNumber('batch')->name('import');
                Route::post('/batches/{batch}/undo', [ExpenseImportController::class, 'undo'])->whereNumber('batch')->name('undo');
                Route::delete('/batches/{batch}', [ExpenseImportController::class, 'destroy'])->whereNumber('batch')->name('destroy');
            });

        // The Shortcut's endpoint: no session or CSRF (it's not a browser), the token authorizes. Per-token limit
        // so one leaked link can't flood a family, plus a per-IP one against token guessing.
        RateLimiter::for('expense-import-pay', fn (Request $request) => [
            Limit::perMinute(20)->by('token:'.$request->route('token')),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);
        Route::middleware(['throttle:expense-import-pay'])
            ->post('/expense-import/pay/{token}', [PaymentController::class, 'store'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('expense_import.pay');
    }
}
