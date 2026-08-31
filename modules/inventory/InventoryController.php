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
use Inertia\Inertia;
use Inertia\Response;

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
