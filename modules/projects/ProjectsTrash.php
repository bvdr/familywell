<?php

namespace Modules\Projects;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;
use Modules\Tasks\Task;

// Projects trash: deleted projects this person can see, and deleted tasks of live projects they can see
// (a project's tasks are listless tasks-module tasks, so TasksTrash, which goes through lists, never lists
// them). Tasks trashed WITH a project come back with it (Project::booted), so they aren't listed separately.
class ProjectsTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        $projects = Project::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (Project $p) => Trash::row($p, 'project', $p->name, 'Projects'));

        $live = Project::visibleTo($user->id)->get(['id', 'name'])->keyBy('id');
        $links = ProjectLink::where('linkable_type', 'task')->whereIn('project_id', $live->keys())->pluck('project_id', 'linkable_id');
        $tasks = Task::onlyTrashed()->whereNull('task_list_id')
            ->where(fn ($q) => $q->whereIn('id', $links->keys())->orWhereIn('parent_id', $links->keys()))->get()
            ->map(fn (Task $t) => Trash::row($t, 'task', $t->title, $live[$links[$t->parent_id ?? $t->id]]->name));

        return $projects->concat($tasks);
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        if ($type === 'project') {
            $project = Project::onlyTrashed()->find($id);
            if (! $project || app(ShareAccess::class)->roleFor($user, $project) === null) {
                return null;
            }
            $project->restore();

            return $project->path();
        }

        $task = $type === 'task' ? Task::onlyTrashed()->whereNull('task_list_id')->find($id) : null;
        $link = $task ? ProjectLink::where(['linkable_type' => 'task', 'linkable_id' => $task->parent_id ?? $task->id])->first() : null;
        $project = $link ? Project::find($link->project_id) : null; // a trashed project: restore the project instead
        if (! $project || app(ShareAccess::class)->roleFor($user, $project) === null) {
            return null;
        }
        $task->restore(); // brings back its trashed parent task first (CascadesTrash)

        return $project->path();
    }
}
