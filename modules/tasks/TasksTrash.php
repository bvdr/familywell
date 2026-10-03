<?php

namespace Modules\Tasks;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Tasks trash: deleted lists the person can see, and deleted tasks whose list (live or trashed) they can
// see. Restoring a task brings back its trashed list/parent first (CascadesTrash).
class TasksTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        $lists = TaskList::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (TaskList $l) => Trash::row($l, 'list', $l->name));
        $tasks = Task::onlyTrashed()->whereHas('list', fn ($q) => $q->withTrashed()->visibleTo($user->id))
            ->with(['list' => fn ($q) => $q->withTrashed()])->get()
            ->map(fn (Task $t) => Trash::row($t, 'task', $t->title, $t->list->name));

        return $lists->concat($tasks);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $model = match ($type) {
            'list' => TaskList::onlyTrashed()->find($id),
            'task' => Task::onlyTrashed()->with(['list' => fn ($q) => $q->withTrashed()])->find($id),
            default => null,
        };
        $list = $model instanceof Task ? $model->list : $model;
        if (! $model || ! $list || app(ShareAccess::class)->roleFor($user, $list) === null) {
            return null;
        }
        $model->restore();

        return "/m/tasks/lists/{$list->id}";
    }
}
