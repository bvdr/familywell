<?php

namespace Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
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

    /** Inventory Overview: summary cards, Needs attention, Recently updated, category browse. */
    public function index(): Response
    {
        $items = InventoryItem::query()->orderBy('name')->get();
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
        ]);
    }

    /** All Items: every item + the filter vocabularies (statuses/categories/locations). Grouping is client-side. */
    public function items(): Response
    {
        $items = InventoryItem::query()->orderBy('name')->get()
            ->map(fn (InventoryItem $i) => $i->toPayload())->values();

        return Inertia::render('inventory/items', [
            'items' => $items,
            'categories' => InventoryCategory::payloadFor(),
            'locations' => InventoryLocation::payloadFor(),
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
        $items = InventoryItem::query()->orderBy('name')->get();

        $low = [];
        $empty = [];
        $expiring = [];
        $stale = [];
        foreach ($items as $i) {
            $state = $i->state();
            if ($state === 'empty') {
                $empty[] = $i;
            } elseif ($state === 'low') {
                $low[] = $i;
            } elseif ($state === 'expiring' || $state === 'expired') {
                $expiring[] = $i;
            } elseif ($i->isStale()) {
                $stale[] = $i;
            }
        }

        // Expiring: soonest first (empty dates last). Stale: quietest first (oldest updated_at first).
        usort($expiring, fn (InventoryItem $a, InventoryItem $b) => ($a->expires_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires_at?->getTimestamp() ?? PHP_INT_MAX));
        usort($stale, fn (InventoryItem $a, InventoryItem $b) => ($a->updated_at?->getTimestamp() ?? 0) <=> ($b->updated_at?->getTimestamp() ?? 0));

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

    /** Add Item: create an item and log its opening stock. Category/location resolve by key or name. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateItem($request);

        $item = InventoryItem::create([
            'name' => $data['name'],
            'quantity' => $data['quantity'],                       // null => the "unknown" state
            'unit' => $data['unit'],
            'category' => $this->resolveCategory($data['category'] ?? null),
            'location' => $this->resolveLocation($data['location'] ?? null),
            'min_quantity' => $data['min_quantity'] ?? 0,
            'target_quantity' => $data['target_quantity'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'auto_replenish' => $data['auto_replenish'] ?? true,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Every item starts its history with an "added" row carrying its opening quantity (null delta
        // when the amount is unknown), so the overview feed and item history show the creation.
        $this->log($item, $item->quantity, 'added', $request->user()->id);

        return back();
    }

    /** Edit Item: update every field. A changed quantity is logged as an adjustment. */
    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        $this->authorizeItem($item);
        $data = $this->validateItem($request);

        $before = $item->quantity; // float|null, captured before the update to detect a quantity change

        $item->update([
            'name' => $data['name'],
            'quantity' => $data['quantity'],
            'unit' => $data['unit'],
            'category' => $this->resolveCategory($data['category'] ?? null),
            'location' => $this->resolveLocation($data['location'] ?? null),
            'min_quantity' => $data['min_quantity'] ?? 0,
            'target_quantity' => $data['target_quantity'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'auto_replenish' => $data['auto_replenish'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);

        // Only log when the quantity actually moved (renaming or re-categorising isn't a stock change).
        // delta is the signed change when both amounts are known; null when either side is "unknown".
        if ($before != $item->quantity) {
            $delta = ($before !== null && $item->quantity !== null) ? (float) $item->quantity - (float) $before : null;
            $this->log($item, $delta, 'adjusted', $request->user()->id);
        }

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

        $before = $item->quantity;              // float|null ("unknown" counts as 0 for the delta)
        $after = (float) $data['quantity'];
        $item->update(['quantity' => $after]);

        // Log the real movement so history stays consistent with the stored amount; consumed vs added by
        // its sign. A no-op (same amount) writes nothing.
        $delta = $after - ($before ?? 0.0);
        if (abs($delta) > 1e-9) {
            $this->log($item, $delta, $delta < 0 ? 'consumed' : 'added', $request->user()->id);
        }

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
    // member (resolveCategory/resolveLocation); the explicit management here — add with a chosen icon,
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

    /** Change a custom category's icon. Built-ins keep their curated icon; the key never changes. */
    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $cat = InventoryCategory::findOrFail($category);     // family-scoped resolve (family middleware ran)
        abort_if($cat->is_default, 403);

        $data = $request->validate(['icon' => ['nullable', 'string', Rule::in(IconLibrary::names())]]);
        $cat->update(['icon' => $data['icon'] ?? InventoryCategory::CUSTOM_ICON]);

        return back();
    }

    /** Delete a category. Blocked while any item still uses its key, so a delete never orphans an item. */
    public function destroyCategory(Request $request, int $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $cat = InventoryCategory::findOrFail($category);

        if (InventoryItem::where('category', $cat->key)->exists()) {
            throw ValidationException::withMessages(['category' => __('inventory.category_in_use')]);
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

    /** Change a custom location's icon. Built-ins keep theirs; the key never changes. */
    public function updateLocation(Request $request, int $location): RedirectResponse
    {
        $this->authorizeManage($request);
        $loc = InventoryLocation::findOrFail($location);
        abort_if($loc->is_default, 403);

        $data = $request->validate(['icon' => ['nullable', 'string', Rule::in(IconLibrary::names())]]);
        $loc->update(['icon' => $data['icon'] ?? InventoryLocation::CUSTOM_ICON]);

        return back();
    }

    /** Delete a location. Blocked while any item still uses its key. */
    public function destroyLocation(Request $request, int $location): RedirectResponse
    {
        $this->authorizeManage($request);
        $loc = InventoryLocation::findOrFail($location);

        if (InventoryItem::where('location', $loc->key)->exists()) {
            throw ValidationException::withMessages(['location' => __('inventory.location_in_use')]);
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

    /**
     * Shared validation for Add/Edit. `category`/`location` come in as a free string that is either an
     * existing key (picked) or a new display name (typed) — resolved to a key by resolve*().
     *
     * @return array<string, mixed>
     */
    private function validateItem(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],           // null => "unknown"
            'unit' => ['required', Rule::in(InventoryItem::UNITS)],
            'category' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:60'],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'target_quantity' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'auto_replenish' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * Turn a category value into a stored key: pass an existing key straight through, and mint a custom
     * category when the user typed a new name. Slug-matching first means typing "Food" reuses the
     * built-in `food` rather than creating a duplicate.
     */
    private function resolveCategory(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $keys = InventoryCategory::keysFor();               // also seeds the family's defaults
        if (in_array($value, $keys, true)) {
            return $value;                                  // already a key
        }
        $slug = Str::slug($value, '_');
        if ($slug !== '' && in_array($slug, $keys, true)) {
            return $slug;                                   // typed name maps onto an existing key
        }

        $familyId = (int) CurrentFamily::id();
        $existing = InventoryCategory::forFamily();
        InventoryCategory::create([
            'key' => $key = InventoryCategory::makeKey($value, $familyId),
            'name' => $value,
            'icon' => InventoryCategory::CUSTOM_ICON,
            'color' => InventoryCategory::CUSTOM_COLORS[$existing->count() % count(InventoryCategory::CUSTOM_COLORS)],
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1,
        ]);

        return $key;
    }

    /** Same resolve-or-create as categories, for locations (no colour). */
    private function resolveLocation(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $keys = InventoryLocation::keysFor();
        if (in_array($value, $keys, true)) {
            return $value;
        }
        $slug = Str::slug($value, '_');
        if ($slug !== '' && in_array($slug, $keys, true)) {
            return $slug;
        }

        $familyId = (int) CurrentFamily::id();
        $existing = InventoryLocation::forFamily();
        InventoryLocation::create([
            'key' => $key = InventoryLocation::makeKey($value, $familyId),
            'name' => $value,
            'icon' => InventoryLocation::CUSTOM_ICON,
            'is_default' => false,
            'position' => ($existing->max('position') ?? 0) + 1,
        ]);

        return $key;
    }

    /** Write one history row. family_id is filled by BelongsToFamily on create. */
    private function log(InventoryItem $item, ?float $delta, string $type, ?int $userId): void
    {
        InventoryLog::create([
            'inventory_item_id' => $item->id,
            'delta' => $delta,
            'type' => $type,
            'user_id' => $userId,
        ]);
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
