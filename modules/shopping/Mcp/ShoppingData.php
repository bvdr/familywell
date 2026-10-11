<?php

namespace Modules\Shopping\Mcp;

use App\Events\ShoppingChanged;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Database\Eloquent\Model;
use Modules\Shopping\ShoppingItem;
use Modules\Shopping\ShoppingList;

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
