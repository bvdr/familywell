<?php

namespace Modules\Shopping\Mcp;

use App\Events\ShoppingChanged;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Database\Eloquent\Model;
use Modules\Notifications\Notify;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;
use Modules\Shopping\ShoppingShop;

// Shared lookups + payload shapes for the Shopping MCP tools. Access follows the web UI: anyone with a
// role on a list may read and edit it (ShareAccess::roleFor, as ShoppingController does), only its
// creator may delete it, and the family scope comes from CurrentFamily.
class ShoppingData
{
    public static function list(User $user, mixed $id): ?ShoppingList
    {
        $list = ShoppingList::find((int) $id);

        return $list && app(ShareAccess::class)->roleFor($user, $list) !== null ? $list : null;
    }

    /**
     * Add an item the way every path does (MCP shopping_add_item, the Add sheet's "Just say it"): a soft duplicate
     * check (warn, never block: returned as the second value), new store names promoted to shops so the Shops view
     * stays complete, a push to the list's people, the realtime refresh. $data is already validated.
     *
     * @return array{0: ShoppingItem, 1: ?ShoppingItem}
     */
    public static function addItem(User $user, ShoppingList $list, array $data): array
    {
        $dup = ShoppingItem::activeDuplicate($list, $data['name']);
        $item = $list->items()->create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'note' => $data['note'] ?? null,
            'store' => $data['store'] ?? null,
            'alt_stores' => $data['alt_stores'] ?? null,
            'created_by' => $user->id,
        ]);
        ShoppingShop::ensure($data['store'] ?? null);
        foreach ($data['alt_stores'] ?? [] as $alt) {
            ShoppingShop::ensure($alt);
        }
        Notify::toAccessOf($list, 'shopping', $user->id, $list->name, "{$user->name} added: {$item->name}", "/m/shopping/lists/{$list->id}");
        self::changed();

        return [$item, $dup];
    }

    public static function item(User $user, mixed $id): ?ShoppingItem
    {
        $item = ShoppingItem::with('list')->find((int) $id);

        return $item && $item->list && self::list($user, $item->list->id) ? $item : null;
    }

    public static function listPayload(ShoppingList $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'store' => $l->store,
            'archived' => $l->archived_at !== null,
            'visibility' => $l->visibility(),
            'url' => self::url("/m/shopping/lists/{$l->id}"),
        ];
    }

    public static function itemPayload(ShoppingItem $i): array
    {
        return [
            'id' => $i->id,
            'list_id' => $i->shopping_list_id,
            'name' => $i->name,
            'quantity' => $i->quantity,
            'note' => $i->note,
            'aisle' => $i->department,
            'store' => $i->store,
            'alt_stores' => $i->alt_stores ?? [],
            'bought' => $i->bought,
            'due_date' => $i->due_date?->toDateString(),
            'url' => self::url("/m/shopping/lists/{$i->shopping_list_id}"),
        ];
    }

    public static function trashInfo(Model $m): array
    {
        return [
            'deleted_at' => $m->deleted_at->toIso8601String(),
            'purged_on' => $m->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ];
    }

    public static function changed(): void
    {
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
