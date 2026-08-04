<?php

namespace Modules\Shopping;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardAgenda;
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

        // "Pick up where you left off": grocery lists this user opened in the last few days, tagged
        // with when so the dashboard can order them across modules. Respects visibility.
        DashboardItems::register('shopping', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('shopping', $familyId)) {
                return [];
            }

            $visits = EntityVisit::recentMap('shopping_list');
            if ($visits->isEmpty()) {
                return [];
            }

            return ShoppingList::query()
                ->withCount(['items', 'items as bought_count' => fn ($q) => $q->where('bought', true)])
                ->with(['shareSetting', 'shares'])
                ->whereIn('id', $visits->keys())
                ->whereNull('archived_at')
                ->get()
                ->filter(fn (ShoppingList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
                ->map(fn (ShoppingList $l) => [
                    'title' => $l->name,
                    'subtitle' => "{$l->bought_count} of {$l->items_count} items",
                    'href' => "/m/shopping/lists/{$l->id}",
                    'icon' => 'shopping-cart',
                    'visitedAt' => $visits[$l->id]->toIso8601String(),
                ])->values()->all();
        });

        // Today / This Week: open grocery items given a due date, due through the end of this week,
        // in a non-archived list the user can see.
        DashboardAgenda::register('shopping', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('shopping', $familyId)) {
                return [];
            }

            $weekEnd = now()->endOfWeek()->toDateString();

            return ShoppingItem::query()
                ->whereNotNull('due_date')
                ->where('bought', false)
                ->whereDate('due_date', '<=', $weekEnd)
                ->with(['list.shareSetting', 'list.shares'])
                ->get()
                ->filter(fn (ShoppingItem $i) => $i->list && ! $i->list->archived_at
                    && ($i->list->visibility() === 'family' || $i->list->shares->contains('user_id', $userId)))
                ->map(fn (ShoppingItem $i) => [
                    'title' => $i->name,
                    'subtitle' => $i->list->name,
                    'href' => "/m/shopping/lists/{$i->shopping_list_id}",
                    'icon' => 'shopping-cart',
                    'dueDate' => $i->due_date->toDateString(),
                    'toggleUrl' => "/m/shopping/items/{$i->id}", // empty-body PATCH toggles bought
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

                // Shops: the family's stores as first-class pages (aggregated cross-list view + aisles).
                Route::get('/shops', [ShoppingController::class, 'shopsIndex'])->name('shops.index');
                Route::post('/shops', [ShoppingController::class, 'storeShop'])->name('shops.store');
                Route::get('/shops/{shop}', [ShoppingController::class, 'shopShow'])->name('shops.show');
                Route::patch('/shops/{shop}', [ShoppingController::class, 'updateShop'])->name('shops.update');
                Route::post('/shops/{shop}/items', [ShoppingController::class, 'storeShopItem'])->name('shops.items.store');
                Route::post('/shops/{shop}/aisles/ensure', [ShoppingController::class, 'ensureShopAisles'])->name('shops.aisles.ensure');
                Route::post('/shops/{shop}/aisles/reorder', [ShoppingController::class, 'reorderShopAisles'])->name('shops.aisles.reorder');
                Route::post('/shops/{shop}/aisles/template', [ShoppingController::class, 'applyShopTemplate'])->name('shops.aisles.template');
                Route::post('/shops/{shop}/aisles', [ShoppingController::class, 'storeShopAisle'])->name('shops.aisles.store');
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

                // Per-shop aisle editor (JSON). Keyed on the list so we derive the store name.
                Route::post('/lists/{list}/aisles/ensure', [ShoppingController::class, 'ensureAisles'])->name('aisles.ensure');
                Route::post('/lists/{list}/aisles/reorder', [ShoppingController::class, 'reorderAisles'])->name('aisles.reorder');
                Route::post('/lists/{list}/aisles/template', [ShoppingController::class, 'applyTemplate'])->name('aisles.template');
                Route::post('/lists/{list}/aisles', [ShoppingController::class, 'storeAisle'])->name('aisles.store');
                Route::patch('/aisles/{aisle}', [ShoppingController::class, 'updateAisle'])->name('aisles.update');
                Route::delete('/aisles/{aisle}', [ShoppingController::class, 'destroyAisle'])->name('aisles.destroy');
            });
    }
}
