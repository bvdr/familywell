<?php

namespace Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Shopping\ShoppingShop;
use Modules\Spending\IconLibrary;

/**
 * Read-only for Phase 1: the Overview, All Items and Item Detail screens, all rendered from real
 * family-scoped data. Every item's state/bar is computed in InventoryItem (one source of truth). Writes
 * (Add/Edit/Quick-Update + the history log) arrive in Phase 2 — the action buttons on these screens are
 * present but inert for now.
 */
class InventoryController extends Controller
{
    // How many rows the overview's "Recently updated" feed shows.
    private const RECENT_LIMIT = 8;

    // How many exception items the dashboard widget surfaces (exceptions only, most urgent first).
    private const WIDGET_EXCEPTIONS = 4;

    /** Inventory Overview: summary cards, Needs attention, Recently updated, category browse. */
    public function index(): Response
    {
        $items = InventoryItem::query()->with('shoppingItem:id,shopping_list_id')->orderBy('name')->get();
        $categories = InventoryCategory::payloadFor();
        $locations = InventoryLocation::payloadFor();

        // Summary counts, computed once from the derived state of each item.
        $byState = $items->groupBy(fn (InventoryItem $i) => $i->state());
        $summary = [
            'total' => $items->count(),
            'low' => $byState->get('low', collect())->count(),
            'empty' => $byState->get('empty', collect())->count(),
            'expiring' => $byState->get('expiring', collect())->count() + $byState->get('expired', collect())->count(),
        ];

        // Needs attention: everything actionable, most-urgent first, then by name.
        $order = array_flip(InventoryItem::STATES); // expired=0 … good=5
        $attention = $items
            ->filter(fn (InventoryItem $i) => $i->needsAttention())
            ->sortBy([fn (InventoryItem $i) => $order[$i->state()] ?? 99, fn (InventoryItem $i) => $i->name])
            ->map(fn (InventoryItem $i) => $i->toPayload())
            ->values();

        // Category browse cards: item count + how many need attention, per category key.
        $catCounts = [];
        foreach ($items as $i) {
            $key = $i->category ?: 'other';
            $catCounts[$key] ??= ['count' => 0, 'attention' => 0];
            $catCounts[$key]['count']++;
            if ($i->needsAttention()) {
                $catCounts[$key]['attention']++;
            }
        }

        return Inertia::render('inventory/overview', [
            'summary' => $summary,
            'attention' => $attention,
            'recent' => $this->recentActivity(),
            'categories' => $categories,
            'locations' => $locations,
            'categoryCounts' => $catCounts,
            // Existing item names, for the Add-item modal's autocomplete (cheap: names only).
            'itemNames' => $items->pluck('name')->unique()->values(),
            'shops' => $this->shopNames(),                       // for the Add-item modal's shop picker
        ]);
    }

    /** All Items: every item + the filter vocabularies (statuses/categories/locations). Grouping is client-side. */
    public function items(): Response
    {
        $items = InventoryItem::query()->with('shoppingItem:id,shopping_list_id')->orderBy('name')->get()
            ->map(fn (InventoryItem $i) => $i->toPayload())->values();

        return Inertia::render('inventory/items', [
            'items' => $items,
            'categories' => InventoryCategory::payloadFor(),
            'locations' => InventoryLocation::payloadFor(),
            'shops' => $this->shopNames(),
            'statuses' => InventoryItem::STATES,
        ]);
    }

    /** Item Detail: the item, its category/location meta, and its recent history. */
    public function show(Request $request, InventoryItem $item): Response
    {
        // Route-model binding resolves before the `family` middleware sets the active tenant, so the
        // BelongsToFamily scope can't be relied on here — guard the family explicitly (same reasoning as
        // ShoppingController's aisle endpoints).
        abort_unless((int) $item->family_id === (int) CurrentFamily::id(), 404);

        $item->load('shoppingItem:id,shopping_list_id'); // for the "Shopping · Added" deep-link on the detail

        $names = $this->memberNames();
        $history = $item->logs()->with('user:id,name')->limit(30)->get()
            ->map(fn (InventoryLog $l) => [
                'id' => $l->id,
                'type' => $l->type,
                'delta' => $l->delta,
                'user' => $l->user?->name ?? ($names[$l->user_id] ?? null),
                'at' => optional($l->created_at)->toIso8601String(),
            ])->values();

        return Inertia::render('inventory/item', [
            'item' => $item->toPayload(),
            'categories' => InventoryCategory::payloadFor(),
            'locations' => InventoryLocation::payloadFor(),
            'shops' => $this->shopNames(),
            'history' => $history,
        ]);
    }

    /**
     * Needs Attention: the focused triage screen. Every actionable item, split into non-overlapping
     * buckets — Empty, Running Low, Expiring Soon (expiring + already-expired), and Stale (fine on stock
     * but gone quiet). An item lands in exactly one bucket (its most urgent signal), so the header count
     * is a straight sum. All buckets are built from the one derived state()/isStale(), never re-derived
     * on the client.
     */
    public function needsAttention(): Response
    {
        $items = InventoryItem::query()->with('shoppingItem:id,shopping_list_id')->orderBy('name')->get();
        ['empty' => $empty, 'low' => $low, 'expiring' => $expiring, 'stale' => $stale] = InventoryItem::attentionBuckets($items);

        $pay = fn (array $rows) => array_map(fn (InventoryItem $i) => $i->toPayload(), $rows);

        return Inertia::render('inventory/needs-attention', [
            'empty' => $pay($empty),
            'low' => $pay($low),
            'expiring' => $pay($expiring),
            'stale' => $pay($stale),
            // The total across every bucket — what the "N items need your attention" subtitle reports.
            'total' => count($empty) + count($low) + count($expiring) + count($stale),
            'categories' => InventoryCategory::payloadFor(),
            'locations' => InventoryLocation::payloadFor(),
        ]);
    }

    /**
     * Categories management + browse: one card per category with its item count and how many need
     * attention. Admins additionally get add / change-icon / delete (a category in use can't be
     * deleted). Cards link into All Items filtered by the category (the "category detail").
     */
    public function categories(Request $request): Response
    {
        $counts = $this->countsByKey('category');
        $rows = InventoryCategory::forFamily()->map(function (InventoryCategory $c) use ($counts) {
            $n = $counts[$c->key] ?? ['count' => 0, 'attention' => 0, 'low' => 0, 'empty' => 0];

            return [...$c->toPayload(), 'id' => $c->id, 'count' => $n['count'], 'attention' => $n['attention']];
        })->values();

        return Inertia::render('inventory/categories', [
            'categories' => $rows,
            'canManage' => $this->canManage($request),
        ]);
    }

    /**
     * Locations management + browse: one card per location with a compact "N items · L low · E empty"
     * summary. Same admin add / change-icon / delete affordances as categories. Cards link into All
     * Items filtered by the location.
     */
    public function locations(Request $request): Response
    {
        $counts = $this->countsByKey('location');
        $rows = InventoryLocation::forFamily()->map(function (InventoryLocation $l) use ($counts) {
            $n = $counts[$l->key] ?? ['count' => 0, 'attention' => 0, 'low' => 0, 'empty' => 0];

            return [...$l->toPayload(), 'id' => $l->id, 'count' => $n['count'], 'low' => $n['low'], 'empty' => $n['empty']];
        })->values();

        return Inertia::render('inventory/locations', [
            'locations' => $rows,
            'canManage' => $this->canManage($request),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------
    // Phase 5 — Settings, the dashboard widget feed, and (via the scheduled command) notifications.
    // ---------------------------------------------------------------------------------------------------

    /**
     * Inventory Settings: the family's default unit system, replenishment auto-add toggles and
     * notification policy, plus links to manage categories/locations. Readable by every member; the
     * write endpoint is admin-only, so `canManage` gates the controls in the UI (server enforces it too).
     */
    public function settings(Request $request, InventoryReplenishment $replenish): Response
    {
        $family = Family::findOrFail(CurrentFamily::id());

        return Inertia::render('inventory/settings', [
            'settings' => InventorySetting::current()->toPayload(),
            'unitSystems' => InventorySetting::UNIT_SYSTEMS,
            // The dedicated replenishment list, for a deep-link — null until the first item is surfaced
            // (it is created lazily by the loop, never by opening Settings).
            'replenishmentList' => ($l = $replenish->existingList()) ? ['id' => $l->id, 'name' => $l->name] : null,
            'canManage' => $request->user()->can('manageMembers', $family),
        ]);
    }

    /**
     * Persist a settings change. Admin-only (hard `authorize`, mirroring the Spending settings). Every
     * field is optional so the screen can instant-save one toggle at a time — only the fields present in
     * the request are updated.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorize('manageMembers', $family);

        $data = $request->validate([
            'unit_system' => ['sometimes', Rule::in(InventorySetting::UNIT_SYSTEMS)],
            'auto_add_low' => ['sometimes', 'boolean'],
            'auto_add_empty' => ['sometimes', 'boolean'],
            'notify_low' => ['sometimes', 'boolean'],
            'notify_empty' => ['sometimes', 'boolean'],
            'notify_expired' => ['sometimes', 'boolean'],
            'notify_stale' => ['sometimes', 'boolean'],
        ]);

        InventorySetting::current()->update($data);

        return back();
    }

    /**
     * JSON feed for the home-screen dashboard widget: the family's headline counts (items / low / empty)
     * plus a few most-urgent exception items. EXCEPTIONS ONLY — never the whole inventory — and the item
     * list is empty when nothing needs attention, so the widget renders nothing on a healthy inventory.
     * Family-scoped (BelongsToFamily) and gated by `module:inventory` on the route, so a family without
     * the module simply 404s and the widget stays hidden.
     */
    public function widget(): JsonResponse
    {
        $items = InventoryItem::query()->get();
        $order = array_flip(InventoryItem::STATES); // expired=0 … good=5, for most-urgent-first ordering

        // The exceptions surfaced in the widget: short or expired stock only, most urgent first, a few.
        // Sort by name then (stably) by state urgency — chained single-key sorts, because the
        // multi-key sortBy([fn, fn]) form orders the state keys incorrectly here.
        $exceptions = $items
            ->filter(fn (InventoryItem $i) => in_array($i->state(), ['expired', 'empty', 'low'], true))
            ->sortBy(fn (InventoryItem $i) => $i->name)
            ->sortBy(fn (InventoryItem $i) => $order[$i->state()] ?? 99)
            ->take(self::WIDGET_EXCEPTIONS)
            ->map(fn (InventoryItem $i) => ['id' => $i->id, 'name' => $i->name, 'state' => $i->state()])
            ->values();

        return response()->json([
            'total' => $items->count(),
            'low' => $items->filter(fn (InventoryItem $i) => $i->state() === 'low')->count(),
            'empty' => $items->filter(fn (InventoryItem $i) => $i->state() === 'empty')->count(),
            'items' => $exceptions,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------
    // Phase 4 — the Inventory <-> Shopping core loop. The screen + two write actions; every rule (which
    // items qualify, idempotency, restock-on-buy) lives in InventoryReplenishment so this stays thin.
    // ---------------------------------------------------------------------------------------------------

    /**
     * Replenishment screen: the low/empty, auto-replenish items that want buying, each shown with its
     * current amount, where it should be topped up to, and either an "Add to Shopping" action or the
     * "Shopping · Added" link when it is already on a list. Plus the bulk "Add all to Shopping".
     */
    public function replenishment(InventoryReplenishment $replenish): Response
    {
        $items = $replenish->replenishable()
            ->load('shoppingItem:id,shopping_list_id')       // deep-link for the "Added" badge
            ->sortBy([
                // Empty before low (emptier is more urgent), then by name.
                fn (InventoryItem $i) => $i->state() === 'empty' ? 0 : 1,
                fn (InventoryItem $i) => $i->name,
            ])
            ->map(fn (InventoryItem $i) => [
                ...$i->toPayload(),
                'replenishTo' => $i->replenishTarget(), // the amount a purchase would top it up to
            ])
            ->values();

        // How many the bulk button would actually add (those not already on a list).
        $pending = $items->filter(fn ($i) => ! $i['onShoppingList'])->count();

        return Inertia::render('inventory/replenishment', [
            'items' => $items,
            'pending' => $pending,
            'categories' => InventoryCategory::payloadFor(),
            'locations' => InventoryLocation::payloadFor(),
        ]);
    }

    /** Per-item "Add to Shopping": surface one item onto the family's default list (idempotent). */
    public function addToShopping(Request $request, InventoryItem $item, InventoryReplenishment $replenish): RedirectResponse
    {
        $this->authorizeItem($item);
        $replenish->addToShopping($item, $request->user());

        return back();
    }

    /** Bulk "Add all low items to Shopping": sweep every qualifying, not-yet-added item onto Shopping. */
    public function addLowToShopping(Request $request, InventoryReplenishment $replenish): RedirectResponse
    {
        $replenish->addAllLow($request->user());

        return back();
    }

    /** Add Item: create an item and log its opening stock. Category/location resolve by key or name. */
    public function store(Request $request): RedirectResponse
    {
        InventoryItem::createFrom($request->validate(InventoryItem::rules()), $request->user()->id);

        return back();
    }

    /** Edit Item: update every field. A changed quantity is logged as an adjustment. */
    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeItem($item);
        $item->applyEdit($request->validate(InventoryItem::rules()), $request->user()->id);

        return back();
    }

    /**
     * Quick Quantity Update: the daily-use path. Takes the new absolute quantity the user landed on
     * (after the −/+ quick buttons or a manual entry) and records the movement. Absolute (not a delta)
     * so the number the user confirmed in the sheet is exactly what gets stored.
     */
    public function adjust(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeItem($item);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0']]);

        $item->setQuantity((float) $data['quantity'], $request->user()->id);

        return back();
    }

    /** Delete an item. Its log rows cascade (FK), so history is removed with it and never orphaned. */
    public function destroy(InventoryItem $item): RedirectResponse
    {
        $this->authorizeItem($item);
        $item->delete();

        // The item detail page is gone — send the user back to the list.
        return redirect()->route('inventory.items');
    }

    // ---------------------------------------------------------------------------------------------------
    // Category + location management (admin only). Inline creation while adding an item stays open to any
    // member (InventoryCategory/Location::resolve); the explicit management here — add with a chosen icon,
    // change a custom icon, delete — is family config, so it is gated on `manageMembers` like the
    // Spending category settings. Reads (the screens above) stay open to every member.
    // ---------------------------------------------------------------------------------------------------

    /** Add a custom category: name + optional icon. Rotates a colour so it reads distinctly in the tiles. */
    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validateTaxonomy($request);

        $existing = InventoryCategory::forFamily();          // seeds defaults so custom rows sort after them
        InventoryCategory::create([
            'key' => InventoryCategory::makeKey($data['name'], (int) CurrentFamily::id()),
            'name' => $data['name'],
            'icon' => $data['icon'] ?? InventoryCategory::CUSTOM_ICON,
            'color' => InventoryCategory::CUSTOM_COLORS[$existing->count() % count(InventoryCategory::CUSTOM_COLORS)],
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1,
        ]);

        return back();
    }

    /**
     * Rename and/or re-icon a category — custom OR a seeded default (Bogdan explicitly wants to edit the
     * defaults). The `key` never changes, so items keep resolving; only the display name/icon move. Every
     * field is optional so the screen can save a rename and an icon change independently.
     *
     * Renaming a default detaches it from its i18n label (is_default -> false) so the family's chosen name
     * is what shows everywhere — an unrenamed default keeps its localised label (a Romanian family still
     * sees "Bucătărie"). An icon-only edit leaves is_default alone, so the localised label survives.
     */
    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $cat = InventoryCategory::findOrFail($category);     // family-scoped resolve (family middleware ran)

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(IconLibrary::names())],
        ]);

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim($data['name']);
            if ($cat->is_default) {
                $updates['is_default'] = false;              // a renamed default becomes the family's own
            }
        }
        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'] ?? InventoryCategory::CUSTOM_ICON;
        }
        if ($updates) {
            $cat->update($updates);
        }

        return back();
    }

    /**
     * Delete a category (custom or default). Blocked while any item still uses its key, so a delete never
     * orphans an item — the error carries the exact count so the UI can say "N items use this category".
     */
    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $cat = InventoryCategory::findOrFail($category);

        $inUse = InventoryItem::where('category', $cat->key)->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages(['category' => __('inventory.category_in_use_n', ['count' => $inUse])]);
        }
        $cat->delete();

        return back();
    }

    /** Add a custom location: name + optional icon (no colour — a location is just an icon + label). */
    public function storeLocation(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validateTaxonomy($request);

        $existing = InventoryLocation::forFamily();
        InventoryLocation::create([
            'key' => InventoryLocation::makeKey($data['name'], (int) CurrentFamily::id()),
            'name' => $data['name'],
            'icon' => $data['icon'] ?? InventoryLocation::CUSTOM_ICON,
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1,
        ]);

        return back();
    }

    /**
     * Rename and/or re-icon a location — custom OR a seeded default. Same rules as updateCategory: the key
     * is stable (items keep resolving), a rename detaches a default from its i18n label so the family's
     * chosen name shows, and an icon-only edit keeps the localised label. Bogdan runs two houses and wants
     * to rename + re-organise the seeded Kitchen/Pantry/… for them.
     */
    public function updateLocation(Request $request, int $location): RedirectResponse
    {
        $this->authorizeManage($request);
        $loc = InventoryLocation::findOrFail($location);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(IconLibrary::names())],
        ]);

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim($data['name']);
            if ($loc->is_default) {
                $updates['is_default'] = false;
            }
        }
        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'] ?? InventoryLocation::CUSTOM_ICON;
        }
        if ($updates) {
            $loc->update($updates);
        }

        return back();
    }

    /**
     * Delete a location (custom or default). Blocked while any item still uses its key; the error carries
     * the count so the UI can say "N items use this location — reassign them first".
     */
    public function destroyLocation(Request $request, int $location): RedirectResponse
    {
        $this->authorizeManage($request);
        $loc = InventoryLocation::findOrFail($location);

        $inUse = InventoryItem::where('location', $loc->key)->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages(['location' => __('inventory.location_in_use_n', ['count' => $inUse])]);
        }
        $loc->delete();

        return back();
    }

    /**
     * Shared validation for adding a category/location: a display name (resolved to a stable slug key
     * server-side) and an optional lucide icon key. The icon set is the app-wide lucide list the
     * IconPicker offers (generated, lives in the Spending module — inventory reuses it rather than ship a
     * second 1,700-entry copy); null keeps the model's fallback icon.
     *
     * @return array{name:string, icon:string|null}
     */
    private function validateTaxonomy(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'icon' => ['nullable', 'string', Rule::in(IconLibrary::names())],
        ]);
        $data['name'] = trim($data['name']);

        return $data;
    }

    /** True when the current user may manage the family's inventory taxonomy (family admin). */
    private function canManage(Request $request): bool
    {
        return $request->user()->can('manageMembers', Family::findOrFail(CurrentFamily::id()));
    }

    /** Guard a taxonomy write to family admins (mirrors the Spending category settings). */
    private function authorizeManage(Request $request): void
    {
        abort_unless($this->canManage($request), 403);
    }

    /**
     * Per-key item tallies for the category/location cards, computed in one pass over the family's items
     * (household inventories are small, so in-memory is cheaper than several grouped queries). Returns
     * key => [count, attention, low, empty]; `$field` is 'category' or 'location'.
     *
     * @return array<string, array{count:int, attention:int, low:int, empty:int}>
     */
    private function countsByKey(string $field): array
    {
        $counts = [];
        foreach (InventoryItem::query()->get() as $item) {
            $key = $item->{$field};
            if ($key === null || $key === '') {
                continue;                                    // uncategorised / no location: not a card
            }
            $counts[$key] ??= ['count' => 0, 'attention' => 0, 'low' => 0, 'empty' => 0];
            $counts[$key]['count']++;
            $state = $item->state();
            if ($item->needsAttention()) {
                $counts[$key]['attention']++;
            }
            if ($state === 'low') {
                $counts[$key]['low']++;
            } elseif ($state === 'empty') {
                $counts[$key]['empty']++;
            }
        }

        return $counts;
    }

    /** The family's shop names, for the item form's shop picker (same source as Shopping's own picker). */
    private function shopNames(): array
    {
        return ShoppingShop::orderBy('name')->pluck('name')->all();
    }

    /**
     * Guard an item belongs to the active family. Route-model binding resolves before the `family`
     * middleware sets the tenant, so the BelongsToFamily scope can't be relied on for the write routes
     * (same reasoning as show()).
     */
    private function authorizeItem(InventoryItem $item): void
    {
        abort_unless((int) $item->family_id === (int) CurrentFamily::id(), 404);
    }

    /** The overview's "Recently updated" feed: the latest quantity changes across all items. */
    private function recentActivity(): Collection
    {
        $names = $this->memberNames();

        return InventoryLog::query()
            ->with(['item:id,name,unit', 'user:id,name'])
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->filter(fn (InventoryLog $l) => $l->item !== null) // skip logs whose item was deleted
            ->map(fn (InventoryLog $l) => [
                'id' => $l->id,
                'itemId' => $l->inventory_item_id,
                'itemName' => $l->item->name,
                'unit' => $l->item->unit,
                'type' => $l->type,
                'delta' => $l->delta,
                'user' => $l->user?->name ?? ($names[$l->user_id] ?? null),
                'at' => optional($l->created_at)->toIso8601String(),
            ])->values();
    }

    /** id => name for the active family's members (fallback for a log whose user relation is missing). */
    private function memberNames(): Collection
    {
        return Family::findOrFail(CurrentFamily::id())->members->pluck('name', 'id');
    }
}
