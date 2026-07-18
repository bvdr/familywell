<?php

namespace Modules\Spending;

use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SpendingServiceProvider extends ServiceProvider
{
    private const CURRENCY_SYMBOL = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei '];

    public function boot(): void
    {
        // Opt spend events into the core sharing system (addressable as `spend_event` in share URLs).
        ShareableRegistry::register('spend_event', SpendEvent::class);

        // Surface the family's spend events on the dashboard (the entities themselves, deep-linked),
        // not just a link to the module. Respects sharing: only events the viewer can see.
        DashboardItems::register('spending', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('spending', $familyId)) {
                return [];
            }

            return SpendEvent::query()
                ->withSum('spends', 'base_amount')
                ->with(['shareSetting', 'shares'])
                ->latest()
                ->get()
                ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId))
                ->take(4)
                ->map(fn (SpendEvent $e) => [
                    'title' => $e->name,
                    'subtitle' => (self::CURRENCY_SYMBOL[$e->currency] ?? $e->currency.' ').number_format((int) $e->spends_sum_base_amount / 100, 2),
                    'href' => "/m/spending/events/{$e->id}",
                    'icon' => 'wallet',
                ])->values()->all();
        });

        // Full Inertia pages (not a dashboard widget): Spending is a whole app section. Gated by
        // `module:spending` so it is only reachable when the family has enabled it.
        Route::middleware(['web', 'auth', 'family', 'module:spending'])
            ->prefix('m/spending')
            ->name('spending.')
            ->group(function () {
                Route::get('/', [SpendingController::class, 'index'])->name('index');
                Route::post('/events', [SpendingController::class, 'storeEvent'])->name('events.store');
                Route::get('/events/{event}', [SpendingController::class, 'show'])->name('show');
                Route::patch('/events/{event}', [SpendingController::class, 'updateEvent'])->name('events.update');
                Route::get('/events/{event}/stats', [SpendingController::class, 'stats'])->name('stats');
                Route::post('/events/{event}/spends', [SpendingController::class, 'storeSpend'])->name('spends.store');
                Route::post('/events/{event}/settle', [SpendingController::class, 'settle'])->name('settle');
                Route::patch('/spends/{spend}', [SpendingController::class, 'updateSpend'])->name('spends.update');
                Route::post('/spends/{spend}/duplicate', [SpendingController::class, 'duplicateSpend'])->name('spends.duplicate');
                Route::get('/spends/receipts/{receipt}', [SpendingController::class, 'receiptFile'])->name('receipts.show');
                Route::delete('/spends/{spend}', [SpendingController::class, 'destroySpend'])->name('spends.destroy');
            });
    }
}
