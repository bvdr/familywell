<?php

namespace Modules\Shopping;

use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShoppingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Opt shopping lists into the core sharing system (addressable as `shopping_list` in share URLs).
        ShareableRegistry::register('shopping_list', ShoppingList::class);

        // Surface the family's grocery lists on the dashboard, deep-linked, respecting visibility.
        DashboardItems::register('shopping', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('shopping', $familyId)) {
                return [];
            }

            return ShoppingList::query()
                ->withCount(['items', 'items as bought_count' => fn ($q) => $q->where('bought', true)])
                ->with(['shareSetting', 'shares'])
                ->whereNull('archived_at')
                ->orderBy('position')
                ->latest()
                ->get()
                ->filter(fn (ShoppingList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
                ->take(4)
                ->map(fn (ShoppingList $l) => [
                    'title' => $l->name,
                    'subtitle' => "{$l->bought_count} of {$l->items_count} items",
                    'href' => "/m/shopping/lists/{$l->id}",
                    'icon' => 'shopping-cart',
                ])->values()->all();
        });

        // Full Inertia pages (a whole app section, not a dashboard widget). Gated by `module:shopping`
        // so it is only reachable when the family has enabled it.
        Route::middleware(['web', 'auth', 'family', 'module:shopping'])
            ->prefix('m/shopping')
            ->name('shopping.')
            ->group(function () {
                Route::get('/', [ShoppingController::class, 'index'])->name('index');
                Route::get('/settings', [ShoppingController::class, 'settings'])->name('settings');
                Route::post('/lists', [ShoppingController::class, 'storeList'])->name('lists.store');
                Route::get('/lists/{list}', [ShoppingController::class, 'show'])->name('show');
                Route::patch('/lists/{list}', [ShoppingController::class, 'updateList'])->name('lists.update');
                Route::post('/lists/{list}/duplicate', [ShoppingController::class, 'duplicateList'])->name('lists.duplicate');
                Route::delete('/lists/{list}', [ShoppingController::class, 'destroyList'])->name('lists.destroy');
                Route::post('/lists/{list}/items', [ShoppingController::class, 'storeItem'])->name('items.store');
                Route::post('/lists/{list}/items/bulk', [ShoppingController::class, 'storeItems'])->name('items.bulk');
                Route::post('/lists/{list}/parse', [ShoppingController::class, 'parseVoice'])->middleware('throttle:20,1')->name('parse');
                Route::patch('/items/{item}', [ShoppingController::class, 'updateItem'])->name('items.update');
                Route::delete('/items/{item}', [ShoppingController::class, 'destroyItem'])->name('items.destroy');
            });
    }
}
