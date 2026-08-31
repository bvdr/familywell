<?php

namespace Modules\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
