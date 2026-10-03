<?php

namespace Modules\Projects;

use App\Models\User;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Notifications\Notify;
use Modules\Tasks\Task;

class Project extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor()

    protected $table = 'projects_projects';

    protected $fillable = ['name', 'description', 'status', 'progress', 'color', 'owner_id', 'target_start', 'waiting_on', 'archived_at', 'position', 'created_by', 'cover_path'];

    protected $casts = ['target_start' => 'date', 'archived_at' => 'datetime'];

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ProjectDependency::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /*
     * Rules shared by ProjectsController and the MCP tools (modules/projects/Mcp), kept here so the web
     * UI and an AI assistant can never drift apart. Callers check access (ShareAccess::roleFor) first.
     */

    /** Create a project owned by $user: owner + creator is $user, and $user gets the `owner` share. */
    public static function createFor(User $user, array $data): self
    {
        $project = static::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'planning',
            'progress' => $data['progress'] ?? 0,
            'color' => $data['color'] ?? 'green',
            'owner_id' => $user->id,
            'target_start' => $data['target_start'] ?? null,
            'created_by' => $user->id,
        ]);
        $project->shares()->create(['user_id' => $user->id, 'role' => 'owner']);
        Notify::toAccessOf($project, 'projects', $user->id, 'New project', "{$user->name} started: {$project->name}", $project->path());

        return $project;
    }

    /** Apply validated edits (only the keys present change); pings the project's people on a status change. */
    public function updateFrom(User $actor, array $data): void
    {
        if (array_key_exists('archived', $data)) {
            $this->archived_at = $data['archived'] ? now() : null;
        }
        $statusBefore = $this->status;
        $this->fill(array_intersect_key($data, array_flip(['name', 'description', 'status', 'progress', 'color', 'owner_id', 'target_start', 'waiting_on'])))->save();
        if (array_key_exists('visibility', $data)) {
            $this->setVisibility($data['visibility']);
        }
        if (array_key_exists('status', $data) && $data['status'] !== $statusBefore) {
            $label = str_replace('_', ' ', $this->status);
            Notify::toAccessOf($this, 'projects', $actor->id, 'Project updated', "{$actor->name} set {$this->name} to {$label}", $this->path());
        }
    }

    /**
     * The project a tasks-module task belongs to. Only top-level tasks carry the `task` link; a sub-task
     * is reached through its parent. Null when the task is not a project task (e.g. a to-do list task).
     */
    public static function forTask(Task $task): ?self
    {
        $link = ProjectLink::where(['linkable_type' => 'task', 'linkable_id' => $task->parent_id ?? $task->id])->first();

        return $link ? static::find($link->project_id) : null;
    }

    /** Is $taskId one of this project's top-level tasks (linked rows are always top-level, see reorder)? */
    public function hasTopTask(int $taskId): bool
    {
        return $this->links()->where(['linkable_type' => 'task', 'linkable_id' => $taskId])->exists()
            && Task::whereKey($taskId)->whereNull('parent_id')->exists();
    }

    /**
     * Add a project task: a listless tasks-module task (task_list_id null), or a sub-task of one of this
     * project's top-level tasks (one level deep, 404 otherwise). Only top-level tasks get the project link.
     */
    public function addTask(User $user, array $data): Task
    {
        $parentId = $data['parent_id'] ?? null;
        abort_if($parentId && ! $this->hasTopTask((int) $parentId), 404);

        // Positions count across all listless siblings (not per project), as they always have.
        $siblingScope = Task::whereNull('task_list_id')->where('parent_id', $parentId);
        $task = Task::create([
            'task_list_id' => null,
            'parent_id' => $parentId,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'position' => (int) $siblingScope->max('position') + 1,
            'created_by' => $user->id,
        ]);
        if (! $parentId) {
            $this->links()->firstOrCreate(['linkable_type' => 'task', 'linkable_id' => $task->id]);
        }
        Notify::toAccessOf($this, 'tasks', $user->id, 'New task', "{$user->name} added: {$data['title']}", $this->path());

        return $task;
    }

    /** Edit one of this project's tasks; pings a new assignee and, when newly done, the project's people. */
    public function updateTask(User $actor, Task $task, array $data): void
    {
        $before = $task->assigned_to;
        $wasDone = $task->done;
        $task->update($data);
        if (! empty($data['assigned_to']) && $data['assigned_to'] !== $before) {
            Notify::push(User::whereKey($data['assigned_to'])->get(), 'tasks', $actor->id, 'Assigned to you', "{$actor->name} assigned you: {$task->title}", $this->path());
        }
        if (! empty($data['done']) && ! $wasDone) {
            Notify::toAccessOf($this, 'tasks', $actor->id, 'Task done', "{$actor->name} completed: {$task->title}", $this->path());
        }
    }

    /** Move a project task (and its sub-tasks) to the trash. A top-level task also loses its project link. */
    public function deleteTask(User $actor, Task $task): void
    {
        if ($task->parent_id === null) {
            ProjectLink::where(['linkable_type' => 'task', 'linkable_id' => $task->id])->delete();
        }
        $task->delete(); // soft delete, cascades to sub-tasks (Task uses CascadesTrash)
        Notify::toAccessOf($this, 'tasks', $actor->id, $this->name, "{$actor->name} deleted: {$task->title}", $this->path());
    }

    /** Relative web path of the project page (notification links). */
    public function path(): string
    {
        return route('projects.show', $this, false);
    }
}
