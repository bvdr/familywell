<?php

namespace Modules\Shopping;

use App\Events\ShoppingChanged;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShoppingController extends Controller
{
    // Aisles items are grouped into. Fixed set (mirrors the spend categories pattern): the frontend
    // owns the icons, the backend only validates and orders. 'other' is the catch-all bucket.
    public const DEPARTMENTS = ['produce', 'dairy', 'bakery', 'pantry', 'household', 'frozen', 'meat', 'beverages', 'other'];

    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

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

        return Inertia::render('shopping/lists', [
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
        ]);
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

        $list = ShoppingList::create([
            'name' => $data['name'],
            'store' => $data['store'] ?? null,
            'color' => $data['color'] ?? 'green',
            'icon' => $data['icon'] ?? 'cart',
            'created_by' => $request->user()->id,
        ]);

        // Creator owns the share (can manage visibility/roles). Defaults to `family` visibility so
        // every member sees it until the owner narrows it.
        $list->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);
        if (! empty($data['visibility'])) {
            $list->setVisibility($data['visibility']);
        }

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

        $family = Family::findOrFail(CurrentFamily::id());
        $members = $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();

        return Inertia::render('shopping/list', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'store' => $list->store,
                'color' => $list->color,
                'icon' => $list->icon,
                'archived' => $list->archived_at !== null,
                'visibility' => $list->visibility(),
            ],
            // Reuse the core sharing UI (ShareSheet); shopping lists are registered as `shopping_list`.
            'share' => $this->shareState($list, $family),
            // Distinct stores the family already uses, for the "Change store" picker chips.
            'stores' => ShoppingList::whereNotNull('store')->distinct()->orderBy('store')->pluck('store')->values(),
            'items' => $list->items()->orderBy('position')->orderBy('id')->get()
                ->map(fn (ShoppingItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'quantity' => $i->quantity,
                    'department' => $i->department,
                    'bought' => $i->bought,
                    'note' => $i->note,
                    'assignedTo' => $i->assigned_to,
                ])->values(),
            'members' => $members,
            'departments' => self::DEPARTMENTS,
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

        if (array_key_exists('visibility', $data)) {
            $list->setVisibility($data['visibility']);
        }

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyList(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($list->created_by === $request->user()->id, 403);
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
            'department' => ['nullable', 'in:'.implode(',', self::DEPARTMENTS)],
            'note' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'department' => $data['department'] ?? 'other',
            'note' => $data['note'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $request->user()->id,
        ]);

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
            $items = (new GroceryParser(self::DEPARTMENTS))->parse($data['transcript']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['items' => $items]);
    }

    // Bulk-insert reviewed voice/AI items into the list in one go.
    public function storeItems(Request $request, ShoppingList $list): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $list) !== null, 403);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'string', 'max:60'],
            'items.*.department' => ['nullable', 'in:'.implode(',', self::DEPARTMENTS)],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['items'] as $i => $item) {
            $list->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'] ?? null,
                'department' => $item['department'] ?? 'other',
                'note' => $item['note'] ?? null,
                'position' => $i,
                'created_by' => $request->user()->id,
            ]);
        }

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function updateItem(Request $request, ShoppingItem $item): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $item->list) !== null, 403);

        // No body => a plain check/uncheck toggle (the common case, one tap in the list).
        if (! $request->hasAny(['name', 'quantity', 'department', 'note', 'assigned_to', 'bought'])) {
            $item->update(['bought' => ! $item->bought]);
        } else {
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
                'department' => ['sometimes', 'in:'.implode(',', self::DEPARTMENTS)],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'assigned_to' => ['sometimes', 'nullable', 'integer'],
                'bought' => ['sometimes', 'boolean'],
            ]);
            $item->update($data);
        }

        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }

    public function destroyItem(Request $request, ShoppingItem $item): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $item->list) !== null, 403);
        $item->delete();
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return back();
    }
}
