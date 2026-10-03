<?php

namespace Modules\Projects\Mcp;

use App\Events\ProjectsChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Collection;
use Modules\Projects\Project;
use Modules\Projects\ProjectLink;
use Modules\Projects\ProjectsController;
use Modules\Tasks\Task;

// Shared lookups + payload shapes for the Projects MCP tools. Access follows ProjectsController exactly:
// anyone with a role on a project (ShareAccess::roleFor) can view and edit it and its tasks, a private
// project of another member is invisible, and the family scope comes from CurrentFamily.
class ProjectData
{
    public const STATUSES = ProjectsController::STATUSES;

    /** A project the user can see, or null (missing, other family, or private to others). */
    public static function project(User $user, mixed $id): ?Project
    {
        $project = Project::find((int) $id);

        return $project && app(ShareAccess::class)->roleFor($user, $project) !== null ? $project : null;
    }

    /**
     * A live project task (or sub-task) plus its project, when the user can see that project. List tasks
     * (task_list_id set) are not project tasks: Project::forTask finds no link for them.
     *
     * @return array{0: Task, 1: Project}|null
     */
    public static function task(User $user, mixed $id): ?array
    {
        $task = Task::find((int) $id);
        $project = $task ? Project::forTask($task) : null;

        return $project && self::project($user, $project->id) ? [$task, $project] : null;
    }

    /**
     * Each project's live top-level tasks (sub-tasks + assignees loaded), in app order, keyed by project
     * id. One query for links and one for tasks, however many projects.
     *
     * @return array<int, Collection<int, Task>>
     */
    public static function topTasks(Collection $projects): array
    {
        $links = ProjectLink::where('linkable_type', 'task')->whereIn('project_id', $projects->pluck('id'))->get(['project_id', 'linkable_id']);
        $tasks = Task::whereIn('id', $links->pluck('linkable_id'))->whereNull('parent_id')
            ->with(['subtasks.assignee', 'assignee'])->orderBy('position')->orderBy('id')->get();

        return $projects->mapWithKeys(fn (Project $p) => [$p->id => $tasks->whereIn('id', $links->where('project_id', $p->id)->pluck('linkable_id'))->values()])->all();
    }

    /**
     * $tasks: this project's top-level tasks. tasks_done/tasks_total count those (not sub-tasks); `progress`
     * is the separate percentage people set by hand in the app.
     */
    public static function projectPayload(Project $p, Collection $tasks): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'status' => $p->status,
            'progress' => $p->progress,
            'tasks_done' => $tasks->where('done', true)->count(),
            'tasks_total' => $tasks->count(),
            'target_start' => $p->target_start?->toDateString(),
            'waiting_on' => $p->status === 'blocked' ? $p->waiting_on : null, // the app only shows it while blocked
            'archived' => $p->archived_at !== null,
            'url' => self::url($p),
        ];
    }

    public static function taskPayload(Task $t, Project $p, bool $withSubtasks = false): array
    {
        $out = [
            'id' => $t->id,
            'parent_id' => $t->parent_id,
            'title' => $t->title,
            'note' => $t->note,
            'done' => $t->done,
            'due_date' => $t->due_date?->toDateString(),
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'project_id' => $p->id,
            'url' => self::url($p),
        ];
        if ($withSubtasks) {
            $out['subtasks'] = $t->subtasks->map(fn (Task $s) => self::taskPayload($s, $p))->values()->all();
        }

        return $out;
    }

    public static function changed(): void
    {
        Realtime::broadcast(new ProjectsChanged((int) CurrentFamily::id()));
    }

    private static function url(Project $p): string
    {
        return rtrim((string) config('app.url'), '/').$p->path();
    }

    /** Family member ids, for validating an assignee or owner (the projects module requires nothing else). */
    public static function memberIds(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members()->pluck('users.id')->all();
    }
}
