<?php

namespace Modules\Shopping;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Shopping trash: deleted lists the person can see, and deleted items whose list they can see. Restoring
// an item brings back its trashed list first (CascadesTrash).
class ShoppingTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        $lists = ShoppingList::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (ShoppingList $l) => Trash::row($l, 'list', $l->name));
        $items = ShoppingItem::onlyTrashed()->whereHas('list', fn ($q) => $q->withTrashed()->visibleTo($user->id))
            ->with(['list' => fn ($q) => $q->withTrashed()])->get()
            ->map(fn (ShoppingItem $i) => Trash::row($i, 'item', $i->name, $i->list->name));

        return $lists->concat($items);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $model = match ($type) {
            'list' => ShoppingList::onlyTrashed()->find($id),
            'item' => ShoppingItem::onlyTrashed()->with(['list' => fn ($q) => $q->withTrashed()])->find($id),
            default => null,
        };
        $list = $model instanceof ShoppingItem ? $model->list : $model;
        if (! $model || ! $list || app(ShareAccess::class)->roleFor($user, $list) === null) {
            return null;
        }
        $model->restore();

        return "/m/shopping/lists/{$list->id}";
    }
}
