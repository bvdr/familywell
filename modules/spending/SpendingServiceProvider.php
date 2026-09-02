<?php

namespace Modules\Spending;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardItems;
use App\Support\Guests\GuestableRegistry;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;

class SpendingServiceProvider extends ServiceProvider
{
    private const CURRENCY_SYMBOL = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei '];

    public function boot(): void
    {
        // Opt spend events into the core sharing system (addressable as `spend_event` in share URLs).
        ShareableRegistry::register('spend_event', SpendEvent::class);

        NotificationCategories::register('spending', 'Spending', 'wallet');
        // Opt them into the core guest-sharing system too (named participants + unguessable link).
        GuestableRegistry::register('spend_event', SpendEvent::class);

        // Surface the family's spend events on the dashboard (the entities themselves, deep-linked),
        // not just a link to the module. Respects sharing: only events the viewer can see.
        DashboardItems::register('spending', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('spending', $familyId)) {
                return [];
            }

            // "Pick up where you left off": events opened in the last few days, tagged with when.
            $visits = EntityVisit::recentMap('spend_event');
            if ($visits->isEmpty()) {
                return [];
            }

            return SpendEvent::query()
                ->withSum('spends', 'base_amount')
                ->with(['shareSetting', 'shares'])
                ->whereIn('id', $visits->keys())
                ->whereNull('archived_at') // archived events drop off the dashboard
                ->get()
                ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $userId))
                ->map(function (SpendEvent $e) use ($visits) {
                    // Show the card in the list's chosen display currency (converted from the frozen
                    // base sum), matching the overview and detail views. Falls back to base.
                    $display = $e->display_currency ?: $e->currency;
                    $total = Fx::convert((int) $e->spends_sum_base_amount, $e->currency, $display);

                    return [
                        'title' => $e->name,
                        'subtitle' => (self::CURRENCY_SYMBOL[$display] ?? $display.' ').number_format($total / 100, 2),
                        'href' => "/m/spending/events/{$e->id}",
                        'category' => 'spending',
                        // The event's own lucide icon key (null keeps the module default on the dashboard).
                        'icon' => $e->icon ?: 'wallet',
                        'visitedAt' => $visits[$e->id]->toIso8601String(),
                    ];
                })->values()->all();
        });

        // Full Inertia pages (not a dashboard widget): Spending is a whole app section. Gated by
        // `module:spending` so it is only reachable when the family has enabled it.
        Route::middleware(['web', 'auth', 'family', 'module:spending'])
            ->prefix('m/spending')
            ->name('spending.')
            ->group(function () {
                Route::get('/', [SpendingController::class, 'index'])->name('index');
                Route::get('/analytics', [SpendingController::class, 'analytics'])->name('analytics');
                // Module settings: default currency + definable categories. Reads are open to members;
                // the write endpoints authorize admin-only inside the controller.
                Route::get('/settings', [SpendingController::class, 'settings'])->name('settings');
                Route::patch('/settings', [SpendingController::class, 'updateSettings'])->name('settings.update');
                Route::post('/categories', [SpendingController::class, 'storeCategory'])->name('categories.store');
                Route::patch('/categories/{category}', [SpendingController::class, 'updateCategory'])->name('categories.update');
                Route::delete('/categories/{category}', [SpendingController::class, 'destroyCategory'])->name('categories.destroy');
                Route::post('/events', [SpendingController::class, 'storeEvent'])->name('events.store');
                Route::post('/sources', [SpendingController::class, 'storeSource'])->name('sources.store');
                // Throttled: each scan is a paid model call, so cap per-user bursts.
                Route::post('/scan-receipt', [SpendingController::class, 'scanReceipt'])->middleware('throttle:20,1')->name('scan');
                Route::get('/events/{event}', [SpendingController::class, 'show'])->name('show');
                Route::patch('/events/{event}', [SpendingController::class, 'updateEvent'])->name('events.update');
                Route::patch('/events/{event}/participants/{key}', [SpendingController::class, 'updateParticipant'])->name('participants.update');
                Route::get('/events/{event}/stats', [SpendingController::class, 'stats'])->name('stats');
                Route::post('/events/{event}/spends', [SpendingController::class, 'storeSpend'])->name('spends.store');
                Route::post('/events/{event}/settle', [SpendingController::class, 'settle'])->name('settle');
                Route::patch('/spends/{spend}', [SpendingController::class, 'updateSpend'])->name('spends.update');
                Route::post('/spends/{spend}/duplicate', [SpendingController::class, 'duplicateSpend'])->name('spends.duplicate');
                Route::post('/spends/{spend}/move', [SpendingController::class, 'moveSpend'])->name('spends.move');
                Route::get('/spends/receipts/{receipt}', [SpendingController::class, 'receiptFile'])->name('receipts.show');
                Route::delete('/spends/{spend}', [SpendingController::class, 'destroySpend'])->name('spends.destroy');
            });

        // Public guest writes: editor guests add/edit/delete their own spends through the guest link.
        // No auth or family middleware; each request is authorized by the link token + the acting
        // guest's role (GuestAccess). Throttled since it's unauthenticated. 'web' gives it a session.
        Route::middleware(['web', 'throttle:60,1'])
            ->prefix('m/spending/guest/{token}')
            ->name('spending.guest.')
            ->group(function () {
                Route::post('/spends', [GuestSpendController::class, 'store'])->name('spends.store');
                Route::patch('/spends/{spend}', [GuestSpendController::class, 'update'])->name('spends.update');
                Route::delete('/spends/{spend}', [GuestSpendController::class, 'destroy'])->name('spends.destroy');
                Route::post('/settle', [GuestSpendController::class, 'settle'])->name('settle');
            });
    }
}
