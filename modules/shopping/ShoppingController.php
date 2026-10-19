<?php

namespace Modules\Shopping;

use App\Events\ShoppingChanged;
use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Notifications\Notify;

class ShoppingController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    // Module-level settings (e.g. the voice dictation language). The settings themselves are a
    // per-device client preference (localStorage), so the page needs no server payload today.
    public function settings(): Response
    {
        return Inertia::render('shopping/settings');
    }

    // ---------------------------------------------------------------------------------------------
    // Shops: a store name promoted to an entity (icon, rename, aisle owner). A shop page aggregates
    // everything you need at that shop across every list you can reach, grouped by the shop's aisles.
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: Collection<int,ShoppingList>, 1: Collection<int,ShoppingList>} */
    private function accessibleLists(int $userId): array
    {
        $lists = ShoppingList::with(['shareSetting', 'shares'])
            ->whereNull('archived_at')->get()
            ->filter(fn (ShoppingList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
            ->values();

        return [$lists, $lists->keyBy('id')];
    }

    // The shop names an item belongs to: its primary shop (own store, else the list's) plus every
    // "also available at" shop. Deduped, blanks dropped.
    private function itemShops(ShoppingItem $item, Collection $listById): array
    {
        $primary = $item->store ?: ($listById[$item->shopping_list_id]->store ?? null);
        $names = array_merge($primary ? [$primary] : [], $item->alt_stores ?? []);

        return array_values(array_unique(array_filter($names)));
    }

    // Same index page, opened on the Shops tab (used by deep links / the shop page's back button).
    public function shopsIndex(Request $request): Response
    {
        return Inertia::render('shopping/lists', $this->indexPayload($request->user()->id) + ['initialView' => 'shops']);
    }

    public function shopShow(Request $request, ShoppingShop $shop): Response
    {
        $user = $request->user();
        [$lists, $listById] = $this->accessibleLists($user->id);
        $aisles = $this->resolveAisles($shop->name); // slug/name/icon, in walk order

        $items = ShoppingItem::whereIn('shopping_list_id', $lists->pluck('id'))
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn (ShoppingItem $i) => in_array($shop->name, $this->itemShops($i, $listById), true))
            ->map(fn (ShoppingItem $i) => $this->presentItem($i, $listById))->values();

        // Lists the user can add to (edit access), for the "which list" pick when adding here; default
        // to their own most-recent list.
        $editable = $lists->filter(fn (ShoppingList $l) => $this->access->roleFor($user, $l) !== null)->sortByDesc('id')->values();
        $defaultList = $editable->firstWhere('created_by', $user->id) ?? $editable->first();

        return Inertia::render('shopping/shop', [
            'shop' => ['id' => $shop->id, 'name' => $shop->name, 'icon' => $shop->icon, 'color' => $shop->color],
            'aisles' => $aisles,
            'items' => $items,
            // For the item editor here: all shops (Shops picker) + the lists a new item can go to.
            'shops' => ShoppingShop::orderBy('name')->pluck('name')->values(),
            'lists' => $editable->map(fn (ShoppingList $l) => ['id' => $l->id, 'name' => $l->name])->values(),
            'defaultListId' => $defaultList?->id,
        ]);
    }

    // The "No shop" bucket: every unassigned item (no own store, no list store, no "also at" shop),
    // gathered from every list you can reach. Reuses the shop detail page in `virtual` mode so you can
    // find these items and give them a store. Registered before the /shops/{shop} route so "none"
    // isn't parsed as a shop id.
    public function shopNone(Request $request): Response
    {
        $user = $request->user();
        [$lists, $listById] = $this->accessibleLists($user->id);

        $items = ShoppingItem::whereIn('shopping_list_id', $lists->pluck('id'))
            ->orderBy('position')->orderBy('id')->get()
            ->filter(fn (ShoppingItem $i) => $this->itemShops($i, $listById) === [])
            ->map(fn (ShoppingItem $i) => $this->presentItem($i, $listById))->values();

        $editable = $lists->filter(fn (ShoppingList $l) => $this->access->roleFor($user, $l) !== null)->sortByDesc('id')->values();
        $defaultList = $editable->firstWhere('created_by', $user->id) ?? $editable->first();

        return Inertia::render('shopping/shop', [
            // id 0 + virtual flag: the frontend hides the shop-only bits (rename, aisles, add-at-shop)
            // and localises the title. Name is set client-side (backend does no shopping i18n).
            'shop' => ['id' => 0, 'name' => '', 'icon' => '', 'color' => ''],
            'virtual' => true,
            'aisles' => [],
            'items' => $items,
            'shops' => ShoppingShop::orderBy('name')->pluck('name')->values(),
            'lists' => $editable->map(fn (ShoppingList $l) => ['id' => $l->id, 'name' => $l->name])->values(),
            'defaultListId' => $defaultList?->id,
        ]);
    }

    /** Shape a shopping item for the shop / no-shop detail pages. */
    private function presentItem(ShoppingItem $i, Collection $listById): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'quantity' => $i->quantity,
            'department' => $i->department,
            'bought' => $i->bought,
            'note' => $i->note,
            'dueDate' => optional($i->due_date)->toDateString(),
            'store' => $i->store,
            'altStores' => $i->alt_stores ?? [],
            'listId' => $i->shopping_list_id,
            'listName' => $listById[$i->shopping_list_id]->name ?? '',
        ];
    }

    // Create a shop by name (from the Shops tab's "+"). Idempotent on name within the family.
    public function storeShop(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        ShoppingShop::firstOrCreate(['name' => trim($data['name'])]);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateShop(Request $request, ShoppingShop $shop): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'icon' => ['sometimes', 'string', 'max:40'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);

        // Rename cascades the store name everywhere it's referenced so items/lists/aisles stay linked;
        // if a shop with the new name already exists this doubles as a merge (cascade, then delete dup).
        if (array_key_exists('name', $data) && trim($data['name']) !== '' && trim($data['name']) !== $shop->name) {
            $old = $shop->name;
            $new = trim($data['name']);
            ShoppingList::where('store', $old)->update(['store' => $new]);
            ShoppingItem::where('store', $old)->update(['store' => $new]);
            ShoppingAisle::where('store', $old)->update(['store' => $new]);
            foreach (ShoppingItem::whereNotNull('alt_stores')->get() as $it) {
                $alts = $it->alt_stores ?? [];
                if (in_array($old, $alts, true)) {
                    $it->alt_stores = array_values(array_unique(array_map(fn ($a) => $a === $old ? $new : $a, $alts)));
                    $it->save();
                }
            }
            $existing = ShoppingShop::where('name', $new)->where('id', '!=', $shop->id)->first();
            if ($existing) {
                ShoppingAisle::where('store', $new)->update(['shop_id' => $existing->id]);
                $shop->delete();
                $shop = $existing;
            } else {
                $shop->name = $new;
            }
        }
        foreach (['icon', 'color'] as $f) {
            if (array_key_exists($f, $data)) {
                $shop->{$f} = $data[$f];
            }
        }
        $shop->save();
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Add an item straight from a shop page. It still has to live in a list, so it goes to the chosen
    // list (must be editable) or the user's default list, with this shop preset as its store.
    public function storeShopItem(Request $request, ShoppingShop $shop): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'list_id' => ['nullable', 'integer'],
            'alt_stores' => ['nullable', 'array'],
            'alt_stores.*' => ['string', 'max:120'],
            'client_key' => ['nullable', 'string', 'max:64'], // see isReplay()
        ]);

        $list = null;
        if (! empty($data['list_id'])) {
            $candidate = ShoppingList::find($data['list_id']);
            if ($candidate && $this->access->roleFor($user, $candidate) !== null) {
                $list = $candidate;
            }
        }
        $list ??= $this->defaultList($user);
        $this->warnIfDuplicate($request, $list, $data['name']);
        if ($this->isReplay($request)) {
            return back();
        }

        $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'department' => $data['department'] ?? 'other',
            'note' => $data['note'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'store' => $shop->name, // the shop you added from is the primary store
            'alt_stores' => $data['alt_stores'] ?? null,
            'created_by' => $user->id,
        ]);
        ShoppingShop::ensure($shop->name);
        foreach ($data['alt_stores'] ?? [] as $alt) {
            ShoppingShop::ensure($alt);
        }
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Soft duplicate warning: an active (unbought) item with the same normalized name is already on the
    // list, so answer with a validation message on `add_anyway` instead of creating. It is NOT a block —
    // the client shows "X is already on this list" with Add anyway (re-posts with add_anyway=1), bump the
    // existing quantity, or cancel. Someone really can want milk on the list twice, so nothing is refused.
    // The PWA queues adds made with no connection and replays them later (resources/js/lib/shopping-outbox.ts).
    // A request can reach us and lose only its response, so the same add may arrive twice: each add carries a
    // client_key, and a key seen in the last day is a replay that must not create a second row. Checked after
    // the duplicate warning so a warned (422) attempt doesn't use up its key. Cache::add is atomic, so two
    // copies racing each other still create one item.
    private function isReplay(Request $request): bool
    {
        $key = $request->input('client_key');

        return is_string($key) && $key !== '' && ! Cache::add("shopping-add:{$request->user()->id}:{$key}", true, now()->addDay());
    }

    private function warnIfDuplicate(Request $request, ShoppingList $list, string $name): void
    {
        if ($request->boolean('add_anyway')) {
            return;
        }
        if ($dup = ShoppingItem::activeDuplicate($list, $name)) {
            throw ValidationException::withMessages([
                'add_anyway' => strtr(Translations::get('shopping.already_on_list'), [':name' => $dup->name]),
            ]);
        }
    }

    // The user's default grocery list. Lives on the model now (ShoppingList::defaultFor) so the Inventory
    // replenishment loop can reuse the exact same "where do loose items land" rule instead of duplicating it.
    private function defaultList(User $user): ShoppingList
    {
        return ShoppingList::defaultFor($user);
    }

    // Shop-scoped aisle editing (the shop's "settings"). Same store-name-keyed logic as the list
    // editor, but addressed by shop and stamping shop_id on the rows.
    public function ensureShopAisles(ShoppingShop $shop): JsonResponse
    {
        $this->materializeAisles($shop->name);
        ShoppingAisle::where('store', $shop->name)->whereNull('shop_id')->update(['shop_id' => $shop->id]);

        return response()->json(['store' => $shop->name, 'aisles' => $this->aisleListJson($shop->name), 'templates' => StoreTemplates::options()]);
    }

    public function storeShopAisle(Request $request, ShoppingShop $shop): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'icon' => ['required', 'string', 'max:40']]);
        $this->materializeAisles($shop->name);
        ShoppingAisle::create([
            'shop_id' => $shop->id,
            'store' => $shop->name,
            'slug' => $this->uniqueSlug($shop->name, $data['name']),
            'name' => $data['name'],
            'icon' => $data['icon'],
            'position' => (int) $this->aisleRows($shop->name)->max('position') + 1,
        ]);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($shop->name)]);
    }

    public function reorderShopAisles(Request $request, ShoppingShop $shop): JsonResponse
    {
        $data = $request->validate(['slugs' => ['required', 'array'], 'slugs.*' => ['string']]);
        foreach (array_values($data['slugs']) as $i => $slug) {
            ShoppingAisle::where('store', $shop->name)->where('slug', $slug)->update(['position' => $i]);
        }
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($shop->name)]);
    }

    public function applyShopTemplate(Request $request, ShoppingShop $shop): JsonResponse
    {
        $data = $request->validate(['template' => ['required', 'in:'.implode(',', array_keys(StoreTemplates::TEMPLATES))]]);
        ShoppingAisle::where('store', $shop->name)->delete();
        foreach (StoreTemplates::TEMPLATES[$data['template']] as $i => [$slug, $name, $icon]) {
            ShoppingAisle::create(['shop_id' => $shop->id, 'store' => $shop->name, 'slug' => $slug, 'name' => $name, 'icon' => $icon, 'position' => $i]);
        }
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($shop->name)]);
    }

    // The shopping index is one page with a Lists / Shops tab switched client-side; both routes render
    // it with the same payload, differing only in which tab opens first.
    public function index(Request $request): Response
    {
        return Inertia::render('shopping/lists', $this->indexPayload($request->user()->id) + ['initialView' => 'lists']);
    }

    private function indexPayload(int $userId): array
    {
        $lists = ShoppingList::query()
            ->withCount([
                'items',
                'items as bought_count' => fn ($q) => $q->where('bought', true),
            ])
            ->with(['shareSetting', 'shares', 'items:id,shopping_list_id,assigned_to'])
            ->orderBy('position')
            ->latest()
            ->get()
            // BelongsToFamily already limits to the current family; `family`-visibility lists are
            // visible to every member, `invited`/`private` need an explicit share row.
            ->filter(fn (ShoppingList $l) => $l->visibility() === 'family' || $l->shares->contains('user_id', $userId))
            ->values();

        // Assignee avatars shown on each list card: distinct members items are assigned to.
        $memberNames = Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id');

        // Shops + per-shop unbought counts across accessible lists (drives the Shops tab).
        [$accessible, $listById] = $this->accessibleLists($userId);
        $counts = [];
        $noShopCount = 0; // unbought items that belong to no shop at all -> the "No shop" bucket
        foreach (ShoppingItem::whereIn('shopping_list_id', $accessible->pluck('id'))->where('bought', false)->get() as $i) {
            $shops = $this->itemShops($i, $listById);
            if ($shops === []) {
                $noShopCount++;

                continue;
            }
            foreach ($shops as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return [
            'lists' => $lists->map(fn (ShoppingList $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'store' => $l->store,
                'color' => $l->color,
                'icon' => $l->icon,
                'itemCount' => $l->items_count,
                'boughtCount' => $l->bought_count,
                'archived' => $l->archived_at !== null,
                'visibility' => $l->visibility(),
                'members' => $l->items->pluck('assigned_to')->filter()->unique()->values()
                    ->map(fn ($id) => ['id' => $id, 'name' => $memberNames[$id] ?? '?'])->all(),
            ])->values(),
            // Distinct stores already in use, offered as chips on the New List screen.
            'stores' => ShoppingList::whereNotNull('store')->distinct()->orderBy('store')->pluck('store')->values(),
            'shops' => ShoppingShop::orderBy('name')->get()->map(fn (ShoppingShop $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'icon' => $s->icon,
                'color' => $s->color,
                'toBuy' => $counts[$s->name] ?? 0,
            ])->values(),
            'noShopCount' => $noShopCount,
        ];
    }

    // Build the ShareState the core ShareSheet expects (visibility, per-member roles, public link).
    private function shareState(ShoppingList $list, Family $family): array
    {
        $visibility = $list->visibility();
        $sharesByUser = $list->shares->keyBy('user_id');
        $link = $list->shareLinks()->first();

        return [
            'type' => 'shopping_list',
            'id' => $list->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $list),
            'members' => $family->members->map(function ($m) use ($sharesByUser, $visibility) {
                $explicit = $sharesByUser->get($m->id)?->role;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null),
                    'isOwner' => $explicit === 'owner',
                ];
            })->values(),
            'link' => $link ? ['id' => $link->id, 'token' => $link->token, 'url' => route('share.public', $link->token)] : null,
        ];
    }

    // Copy a list and its items (reset to unbought) into a fresh list the current user owns.
    public function duplicateList(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($this->access->canView($request->user(), $list), 403);

        $copy = ShoppingList::create([
            'name' => $list->name.' (copy)',
            'store' => $list->store,
            'color' => $list->color,
            'icon' => $list->icon,
            'created_by' => $request->user()->id,
        ]);
        $copy->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        foreach ($list->items()->orderBy('position')->get() as $item) {
            $copy->items()->create([
                'name' => $item->name,
                'quantity' => $item->quantity,
                'department' => $item->department,
                'note' => $item->note,
                'assigned_to' => $item->assigned_to,
                'position' => $item->position,
                'created_by' => $request->user()->id,
            ]);
        }

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return to_route('shopping.show', $copy);
    }

    public function storeList(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:20'],
            'visibility' => ['nullable', 'in:family,invited,private'],
        ]);

        // Creator owns the share; store promoted to a shop; `family` visibility unless narrowed. See createFor().
        $list = ShoppingList::createFor($request->user(), $data, $data['visibility'] ?? null);

        Notify::toAccessOf($list, 'shopping', $request->user()->id, 'New shopping list', "{$request->user()->name} started: {$list->name}", route('shopping.show', $list, false));
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return to_route('shopping.show', $list);
    }

    public function show(Request $request, ShoppingList $list): Response
    {
        // Self-heal lists created before an owner share existed. Idempotent.
        if (! $list->shares()->where('role', 'owner')->exists()) {
            $list->shares()->firstOrCreate(['user_id' => $list->created_by], ['role' => 'owner']);
        }

        abort_unless($this->access->canView($request->user(), $list), 403);

        // Mark this list as "just opened" for the dashboard's Pick-up-where-you-left-off.
        EntityVisit::record('shopping_list', $list->id);

        $family = Family::findOrFail(CurrentFamily::id());
        // Only members who can reach the list are assignable ("For"): everyone for `family`
        // visibility, otherwise just those with a share. No point assigning an item to someone who
        // can't see the list.
        $shared = $list->shares->pluck('user_id');
        $members = $family->members
            ->filter(fn ($m) => $list->visibility() === 'family' || $shared->contains($m->id))
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();

        $items = $list->items()->orderBy('position')->orderBy('id')->get();
        $familyStores = ShoppingList::whereNotNull('store')->distinct()->orderBy('store')->pluck('store')->values();

        // Every shop this list touches (default + item primaries + item alternatives) plus the family's
        // known shops, so grouping and the item store picker have each shop's aisles on hand. Keyed by
        // shop name; a brand-new store the client types falls back to `defaultAisles`.
        $storeNames = collect([$list->store])
            ->merge($items->pluck('store'))
            ->merge($items->pluck('alt_stores')->flatten())
            ->merge($familyStores)
            ->filter()->unique()->values();
        $aislesByStore = [];
        foreach ($storeNames as $s) {
            $aislesByStore[$s] = $this->resolveAisles($s);
        }

        return Inertia::render('shopping/list', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'store' => $list->store, // the list's default store, pre-selected for new items
                'color' => $list->color,
                'icon' => $list->icon,
                'archived' => $list->archived_at !== null,
                'visibility' => $list->visibility(),
            ],
            // Reuse the core sharing UI (ShareSheet); shopping lists are registered as `shopping_list`.
            'share' => $this->shareState($list, $family),
            // Distinct stores the family already uses (aisle keys, grouping).
            'stores' => $familyStores,
            // Every shop the family has, for the item editor's Shops picker (superset of `stores`).
            'shops' => ShoppingShop::orderBy('name')->pluck('name')->values(),
            // name => id, so a store header can deep-link to that shop's page.
            'shopIds' => ShoppingShop::pluck('id', 'name'),
            'items' => $items->map(fn (ShoppingItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'quantity' => $i->quantity,
                'department' => $i->department,
                'bought' => $i->bought,
                'note' => $i->note,
                'assignedTo' => $i->assigned_to,
                'dueDate' => optional($i->due_date)->toDateString(),
                'store' => $i->store,                 // primary shop (null => inherits the list store)
                'altStores' => $i->alt_stores ?? [],  // also-available-at shops
            ])->values(),
            'members' => $members,
            // Each shop's aisles (custom rows or the default Grocery fallback) drive the grouping and the
            // item's department chips. Grouping is store -> aisle now that items can span shops.
            'aislesByStore' => $aislesByStore,
            'defaultAisles' => $this->resolveAisles(null),
            // Drives the "Add by voice" entry + the "Organized by AI" badge; false falls back to a naive split.
            'aiEnabled' => GroceryParser::configured(),
        ]);
    }

    public function updateList(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'store' => ['sometimes', 'nullable', 'string', 'max:120'],
            'color' => ['sometimes', 'string', 'max:20'],
            'icon' => ['sometimes', 'string', 'max:20'],
            'archived' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:family,invited,private'],
        ]);

        if (array_key_exists('archived', $data)) {
            $list->archived_at = $data['archived'] ? now() : null;
        }
        $list->fill(array_intersect_key($data, array_flip(['name', 'store', 'color', 'icon'])))->save();

        // Promote the list's store to a real shop so it gets a card in the Shops tab and the items that
        // inherit it (own store null) are reachable there, not stranded in the "No shop" bucket.
        if (array_key_exists('store', $data)) {
            ShoppingShop::ensure($data['store']);
        }

        if (array_key_exists('visibility', $data)) {
            $list->setVisibility($data['visibility']);
        }

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyList(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($list->created_by === $request->user()->id, 403);
        // Notify BEFORE delete — recipients are derived from the list's shares/visibility.
        Notify::toAccessOf($list, 'shopping', $request->user()->id, 'Shopping', "{$request->user()->name} deleted list: {$list->name}", route('shopping.index', [], false));
        $list->delete();
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return to_route('shopping.index');
    }

    public function storeItem(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:60'],
            // Aisles are per-shop and dynamic now, so accept any slug; the client only offers the
            // shop's aisles, and the list groups unknown slugs under a catch-all "Other".
            'department' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'store' => ['nullable', 'string', 'max:120'],           // primary shop; null => inherit list store
            'alt_stores' => ['nullable', 'array'],                  // also-available-at shops
            'alt_stores.*' => ['string', 'max:120'],
            'client_key' => ['nullable', 'string', 'max:64'], // see isReplay()
        ]);
        $this->warnIfDuplicate($request, $list, $data['name']);
        if ($this->isReplay($request)) {
            return back();
        }

        $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'department' => $data['department'] ?? 'other',
            'note' => $data['note'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'store' => $data['store'] ?? null,
            'alt_stores' => $data['alt_stores'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Promote any newly-typed store name to a shop so it shows in the Shops list.
        ShoppingShop::ensure($data['store'] ?? null);
        foreach ($data['alt_stores'] ?? [] as $alt) {
            ShoppingShop::ensure($alt);
        }

        Notify::toAccessOf($list, 'shopping', $request->user()->id, $list->name, "{$request->user()->name} added: {$data['name']}", route('shopping.show', $list, false));
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    // Voice/AI: turn a spoken brain-dump into aisle-sorted items for the review screen. Returns plain
    // JSON (not an Inertia redirect) because the client shows the parsed items for editing, then bulk-
    // adds them. 404 when Gemini isn't configured, so the client falls back to a naive local split.
    public function parseVoice(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless(GroceryParser::configured(), 404);
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate(['transcript' => ['required', 'string', 'max:2000']]);

        try {
            // Sort into THIS shop's aisles (e.g. plumbing/electrical for a bricolage run), not the default.
            $items = (new GroceryParser($this->aisleSlugs($list->store)))->parse($data['transcript']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['items' => ShoppingItem::flagOnList($list, ShoppingItem::dedupeNames($items))]);
    }

    // Paste a block of text (a list copied from WhatsApp / notes / another chat) and get candidate
    // items back for the review screen. Always answers: the naive line/comma split needs no AI; when
    // Gemini is configured the cleaned lines go through it for aisles + tidier quantities, falling back
    // to the naive result if the model call fails, so paste never depends on the AI being up.
    public function parsePaste(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:5000']]);

        $items = GroceryParser::splitPasted($data['text']);
        if ($items !== [] && GroceryParser::configured()) {
            try {
                // Feed the de-noised lines (qty folded back in) so the model sorts aisles, not bullets.
                $lines = array_map(fn ($i) => trim(($i['quantity'] ? $i['quantity'].' ' : '').$i['name']), $items);
                $items = (new GroceryParser($this->aisleSlugs($list->store)))->parse(implode("\n", $lines)) ?: $items;
            } catch (\RuntimeException) {
                // keep the naive split
            }
        }

        // Same name twice in one paste ("milk" on two lines) reviews as one row; rows already on the list
        // are tagged (on_list) for the review screen but kept — a real repeat is legit, the user decides.
        return response()->json(['items' => ShoppingItem::flagOnList($list, ShoppingItem::dedupeNames($items))]);
    }

    // Bulk-insert reviewed voice/AI items into the list in one go.
    public function storeItems(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'string', 'max:60'],
            'items.*.department' => ['nullable', 'string', 'max:60'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $list->addItems($request->user(), $data['items']);

        return back();
    }

    public function updateItem(Request $request, ShoppingItem $item): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $item->list) !== null, 403);

        $wasBought = $item->bought; // captured before the update so we can spot the not-bought -> bought edge

        // No body => a plain check/uncheck toggle (the common case, one tap in the list).
        if (! $request->hasAny(['name', 'quantity', 'department', 'note', 'assigned_to', 'due_date', 'bought', 'store', 'alt_stores'])) {
            $item->update(['bought' => ! $item->bought]);
        } else {
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
                'department' => ['sometimes', 'string', 'max:60'],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'assigned_to' => ['sometimes', 'nullable', 'integer'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'bought' => ['sometimes', 'boolean'],
                'store' => ['sometimes', 'nullable', 'string', 'max:120'],
                'alt_stores' => ['sometimes', 'nullable', 'array'],
                'alt_stores.*' => ['string', 'max:120'],
            ]);
            $item->update($data);
            // Promote any newly-typed store name to a shop so it shows in the Shops list.
            ShoppingShop::ensure($data['store'] ?? null);
            foreach ($data['alt_stores'] ?? [] as $alt) {
                ShoppingShop::ensure($alt);
            }
        }

        // "Shopping done" ping + the `shopping.item.bought` hook (Inventory's replenishment loop listens),
        // shared with the MCP tools. Hook fires only on the false -> true edge. See ShoppingItem::settleBought.
        $item->settleBought($wasBought, $request->user());

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyItem(Request $request, ShoppingItem $item): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $item->list) !== null, 403);
        $list = $item->list;
        $name = $item->name;
        $item->delete();

        Notify::toAccessOf($list, 'shopping', $request->user()->id, $list->name, "{$request->user()->name} removed: {$name}", route('shopping.show', $list, false));
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    // ---------------------------------------------------------------------------------------------
    // Aisles: per-shop, keyed by the list's free-text store name. Shared family config (behind the
    // `family` + `module:shopping` middleware), so any family member may edit; the BelongsToFamily
    // scope keeps other families out. The editor opens from a list, so endpoints take the list and
    // derive its store. A shop with no rows uses the default Grocery set until it is edited.
    // ---------------------------------------------------------------------------------------------

    /** @return Collection<int, ShoppingAisle> */
    private function aisleRows(?string $store)
    {
        return ShoppingAisle::where('store', (string) $store)->orderBy('position')->orderBy('id')->get();
    }

    /** This shop's aisles as {slug,name,icon}, falling back to the default Grocery set. */
    private function resolveAisles(?string $store): array
    {
        return ShoppingAisle::resolve($store, (int) CurrentFamily::id());
    }

    private function aisleSlugs(?string $store): array
    {
        return array_column($this->resolveAisles($store), 'slug');
    }

    // Persist the default set for a shop that only has the virtual fallback, so its aisles become
    // editable rows. No-op once the shop has custom rows.
    private function materializeAisles(?string $store): void
    {
        if ($this->aisleRows($store)->isNotEmpty()) {
            return;
        }
        foreach (StoreTemplates::default() as $i => [$slug, $name, $icon]) {
            ShoppingAisle::create(['store' => (string) $store, 'slug' => $slug, 'name' => $name, 'icon' => $icon, 'position' => $i]);
        }
    }

    private function uniqueSlug(?string $store, string $name): string
    {
        $base = Str::slug($name) ?: 'aisle';
        $slug = $base;
        $taken = $this->aisleRows($store)->pluck('slug')->all();
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function aisleListJson(?string $store): array
    {
        return $this->aisleRows($store)
            ->map(fn (ShoppingAisle $a) => ['id' => $a->id, 'slug' => $a->slug, 'name' => $a->name, 'icon' => $a->icon, 'position' => $a->position])
            ->values()->all();
    }

    // Which shop's aisles an editor request targets. A list spans several shops now, so the client
    // says which one; falls back to the list's default store.
    private function aisleStore(Request $request, ShoppingList $list): ?string
    {
        return $request->filled('store') ? (string) $request->input('store') : $list->store;
    }

    // Editor opens: materialize the fallback into editable rows, then return them + template options.
    public function ensureAisles(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $store = $this->aisleStore($request, $list);
        $this->materializeAisles($store);

        return response()->json([
            'store' => $store,
            'aisles' => $this->aisleListJson($store),
            'templates' => StoreTemplates::options(),
        ]);
    }

    public function storeAisle(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'icon' => ['required', 'string', 'max:40']]);
        $store = $this->aisleStore($request, $list);
        $this->materializeAisles($store);

        ShoppingAisle::create([
            'store' => (string) $store,
            'slug' => $this->uniqueSlug($store, $data['name']),
            'name' => $data['name'],
            'icon' => $data['icon'],
            'position' => (int) $this->aisleRows($store)->max('position') + 1,
        ]);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($store)]);
    }

    public function updateAisle(Request $request, ShoppingAisle $aisle): JsonResponse
    {
        // Route-model binding runs before the `family` middleware sets the tenant, so it is not
        // family-scoped. Guard explicitly: the aisle must belong to the caller's active family.
        abort_unless((int) $aisle->family_id === (int) CurrentFamily::id(), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'icon' => ['sometimes', 'string', 'max:40'],
        ]);
        $aisle->update($data);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($aisle->store)]);
    }

    public function destroyAisle(Request $request, ShoppingAisle $aisle): JsonResponse
    {
        abort_unless((int) $aisle->family_id === (int) CurrentFamily::id(), 404);
        $store = $aisle->store;
        $aisle->delete();
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($store)]);
    }

    // Reorder a shop's aisles to the given slug order (drives the walk order in the list + focus mode).
    public function reorderAisles(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate(['slugs' => ['required', 'array'], 'slugs.*' => ['string']]);
        $store = $this->aisleStore($request, $list);

        foreach (array_values($data['slugs']) as $i => $slug) {
            ShoppingAisle::where('store', (string) $store)->where('slug', $slug)->update(['position' => $i]);
        }
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($store)]);
    }

    // Replace this shop's aisles with a starter template.
    public function applyTemplate(Request $request, ShoppingList $list): JsonResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);
        $data = $request->validate(['template' => ['required', 'in:'.implode(',', array_keys(StoreTemplates::TEMPLATES))]]);
        $store = $this->aisleStore($request, $list);

        ShoppingAisle::where('store', (string) $store)->delete();
        foreach (StoreTemplates::TEMPLATES[$data['template']] as $i => [$slug, $name, $icon]) {
            ShoppingAisle::create(['store' => (string) $store, 'slug' => $slug, 'name' => $name, 'icon' => $icon, 'position' => $i]);
        }
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['aisles' => $this->aisleListJson($store)]);
    }
}
