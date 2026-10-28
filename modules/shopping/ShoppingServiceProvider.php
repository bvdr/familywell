<?php

namespace Modules\Shopping;

use App\Models\EntityVisit;
use App\Models\User;
use App\Support\Add\AddContext;
use App\Support\Add\AddKinds;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Search\SearchSources;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\Notifications\NotificationCategories;
use Modules\Shopping\Mcp\ShoppingData;

class ShoppingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Just say it" in the Add sheet (AddKinds): things to buy, or a whole new shopping list. Items go to the list
        // on screen, else the list this person added to last, else their first list (else a new one); the card names
        // that list up front (target). Already on the list (not bought) = merged, not added twice (design H2c).
        $pickList = function (User $user, AddContext $ctx): ?ShoppingList {
            $lastList = ShoppingItem::where('created_by', $user->id)->latest('id')->value('shopping_list_id');

            return ($ctx->module === 'shopping' && $ctx->entityId ? ShoppingData::list($user, $ctx->entityId) : null)
                ?? ($lastList ? ShoppingData::list($user, $lastList) : null)
                ?? ShoppingList::visibleTo($user->id)->whereNull('archived_at')->orderBy('position')->first();
        };
        AddKinds::register('shopping_item', 'shopping', [
            'fields' => [
                // Each thing with its amount ("six pack" of "Schweppes Lămâie și Zmeură"): the reader splits them.
                'items' => ['type' => 'list', 'required' => true, 'of' => ['name' => 'text', 'quantity' => 'text']],
            ],
            'target' => fn (User $user, AddContext $ctx) => $pickList($user, $ctx)?->name ?? Translations::get('nav.shopping'),
            'save' => function (User $user, array $v, AddContext $ctx) use ($pickList) {
                // {name, quantity} per thing (a bare string still works: just the name).
                $items = array_values(array_filter(array_map(fn ($i) => [
                    'name' => mb_substr(trim((string) (is_array($i) ? ($i['name'] ?? '') : $i)), 0, 255),
                    'quantity' => is_array($i) && filled($i['quantity'] ?? null) ? mb_substr(trim((string) $i['quantity']), 0, 60) : null,
                ], (array) ($v['items'] ?? [])), fn ($i) => $i['name'] !== ''));
                throw_if($items === [], ValidationException::withMessages(['items' => 'Nothing to buy was read.']));
                $list = $pickList($user, $ctx) ?? ShoppingList::createFor($user, ['name' => Translations::get('nav.shopping')]);
                foreach ($items as $item) {
                    if (! ShoppingItem::activeDuplicate($list, $item['name'])) {
                        ShoppingData::addItem($user, $list, $item);
                    }
                }

                return ['title' => implode(', ', array_column($items, 'name')), 'url' => "/m/shopping/lists/{$list->id}"];
            },
        ]);
        AddKinds::register('shopping_list', 'shopping', [
            'fields' => [
                'name' => ['type' => 'text', 'required' => true],
            ],
            'save' => function (User $user, array $v) {
                $data = Validator::validate(['name' => $v['name'] ?? null], ['name' => ['required', 'string', 'max:255']]);
                $list = ShoppingList::createFor($user, $data);
                ShoppingData::changed();

                return ['title' => $list->name, 'url' => "/m/shopping/lists/{$list->id}"];
            },
        ]);
        AddKinds::screen('shopping', fn (?int $id, User $user) => $id && ($l = ShoppingData::list($user, $id)) ? "the shopping list {$l->name}" : null);

        // "Your modules" tile: what's still to buy on the lists this person can see.
        DashboardSummaries::register('shopping', fn (int $userId) => ['key' => 'dashboard.sum_shopping', 'params' => [
            'count' => ShoppingItem::where('bought', false)->whereHas('list', fn ($q) => $q->visibleTo($userId))->count(),
        ]]);
        // Adding a ShoppingItem counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('shopping', ShoppingItem::class);

        // MCP: tools an AI assistant can use on this module, gated by the `shopping:read` / `shopping:write`
        // scopes the person grants on the consent screen (see docs/mcp.md).
        McpTools::register('shopping', 'Shopping', [
            Mcp\ListLists::class, Mcp\GetList::class, Mcp\Search::class,
            Mcp\CreateList::class, Mcp\UpdateList::class, Mcp\AddItem::class, Mcp\UpdateItem::class,
            Mcp\DeleteList::class, Mcp\DeleteItem::class, Mcp\ListDeleted::class, Mcp\Restore::class,
        ]);

        // Reversible delete: trashed rows are purged after the retention window (trash:purge).
        // What this module shows in Settings > Recently deleted and its MCP trash tools.
        Trash::source('shopping', ShoppingTrash::class);
        Trash::register(ShoppingList::class);
        Trash::register(ShoppingItem::class);
        // Opt shopping lists into the core sharing system (addressable as `shopping_list` in share URLs).
        ShareableRegistry::register('shopping_list', ShoppingList::class);

        NotificationCategories::register('shopping', 'Shopping', 'shopping-cart');

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
                    'category' => 'shopping',
                    // The list's own icon slug + colour swatch (lib/shopping.ts resolves them, like the lists page).
                    'icon' => $l->icon ?: 'cart',
                    'color' => $l->color,
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

            $weekEnd = DashboardAgenda::until();

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
                    'toggleUrl' => "/m/shopping/items/{$i->id}", 'toggleBody' => ['bought' => true], // the dashboard tick sends this: set bought, never flip
                    'kind' => 'shopping_item', // kind + id: get_today's widget ticks it off via the MCP tool
                    'id' => $i->id,
                ])->values()->all();
        });

        // H5 search: grocery items (name/note) AND the lists themselves (name/store), each a hit of its
        // own, capped at 20 total so one module can't crowd out the others. Only lists/items this user
        // can see (visibleTo), same rule as shopping_search above.
        SearchSources::register('shopping', function (string $q, int $userId) {
            $rows = [];
            $items = SearchSources::ilike(
                ShoppingItem::query()->with('list')->whereHas('list', fn ($l) => $l->visibleTo($userId)),
                ['name', 'note'], $q
            )->orderByDesc('id')->limit(20)->get();
            foreach ($items as $item) {
                $rows[] = [
                    'module' => 'shopping', 'title' => $item->name,
                    'meta' => trim(implode(' · ', array_filter([
                        $item->list?->name, $item->quantity,
                        Translations::get($item->bought ? 'search.bought' : 'search.to_buy'),
                    ]))),
                    'href' => "/m/shopping/lists/{$item->shopping_list_id}", 'icon' => 'shopping-cart',
                ];
            }
            $lists = SearchSources::ilike(
                ShoppingList::query()->visibleTo($userId)->whereNull('archived_at'),
                ['name', 'store'], $q
            )->orderByDesc('id')->limit(20)->get();
            foreach ($lists as $list) {
                $rows[] = [
                    'module' => 'shopping', 'title' => $list->name,
                    'meta' => $list->store ?: Translations::get('nav.shopping'),
                    'href' => "/m/shopping/lists/{$list->id}", 'icon' => 'shopping-cart',
                ];
            }

            return array_slice($rows, 0, 20);
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
                // Literal before the {shop} wildcard so "none" isn't route-model-bound as a shop id.
                Route::get('/shops/none', [ShoppingController::class, 'shopNone'])->name('shops.none');
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
                Route::post('/lists/{list}/paste', [ShoppingController::class, 'parsePaste'])->middleware('throttle:20,1')->name('paste');
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
