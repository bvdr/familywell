<?php

namespace Modules\Tasks\Mcp;

use App\Events\TasksChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Notifications\Notify;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

// Shared lookups + payload shapes for the Tasks MCP tools. Access follows the web UI exactly: a list is
// readable and editable by anyone with a role on it (ShareAccess::roleFor, same check TasksController
// uses), only its creator may delete it, and the family scope comes from CurrentFamily.
class TaskData
{
    /** A live list the user can see, or null (missing, other family, trashed, or private to others). */
    public static function list(User $user, mixed $id): ?TaskList
    {
        $list = TaskList::find((int) $id);

        return $list && app(ShareAccess::class)->roleFor($user, $list) !== null ? $list : null;
    }

    /** A live list-task the user can see through its list. Project (listless) tasks are out of scope. */
    public static function task(User $user, mixed $id): ?Task
    {
        $task = Task::with('list')->find((int) $id);

        return $task && $task->list && self::list($user, $task->list->id) ? $task : null;
    }

    /** Family member ids, for validating an assignee. */
    /**
     * Add a task to a list the way every path does (MCP tasks_create_task, the Add sheet's "Just say it"): the task,
     * its extension fields from $args (important, recurrence...), a push to the list's people, the realtime refresh.
     * $data is already validated.
     */
    public static function addTask(User $user, TaskList $list, array $data, array $args = []): Task
    {
        $task = $list->addTask($user, $data);
        self::fireSaved($task, $user, $args);
        Notify::toAccessOf($list, 'tasks', $user->id, 'New task', "{$user->name} added: {$task->title}", "/m/tasks/lists/{$list->id}");
        self::changed();

        return $task;
    }

    public static function memberIds(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members()->pluck('users.id')->all();
    }

    public static function listPayload(TaskList $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'color' => $l->color,
            'icon' => $l->icon,
            'archived' => $l->archived_at !== null,
            'visibility' => $l->visibility(),
            'url' => self::url("/m/tasks/lists/{$l->id}"),
        ];
    }

    public static function taskPayload(Task $t, bool $withSubtasks = false): array
    {
        $out = [
            'id' => $t->id,
            'list_id' => $t->task_list_id,
            'parent_id' => $t->parent_id,
            'title' => $t->title,
            'note' => $t->note,
            'done' => $t->done,
            'due_date' => $t->due_date?->toDateString(),
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'url' => self::url("/m/tasks/lists/{$t->task_list_id}"),
        ];
        if ($withSubtasks) {
            $out['subtasks'] = $t->subtasks->map(fn (Task $s) => self::taskPayload($s))->values()->all();
        }

        // Same `task.serialize` data hook the web UI runs, so extension modules add their fields here too
        // (important_todos -> `important`, recurring_todos -> `recurrence`), each only when enabled.
        return app(Hooks::class)->filter('task.serialize', $out, $t);
    }

    /**
     * Extra tool parameters contributed by extension modules via the `task.mcp.schema` filter (e.g.
     * `important`, `recurrence`). Listeners self-gate on their module being enabled, so a family without
     * them never sees the parameters.
     */
    public static function extensionSchema(JsonSchema $schema): array
    {
        return app(Hooks::class)->filter('task.mcp.schema', [], $schema);
    }

    /**
     * Fire the same `task.saved` hook the web editor fires after a create/edit, with the tool's arguments
     * as the request body. Extensions persist their own fields from it (important flag, recurrence rule),
     * and Vehicles completes a linked maintenance schedule when its task is marked done, exactly as in the
     * app. The synthetic request resolves user() to the token's user, which those listeners rely on.
     */
    public static function fireSaved(Task $task, User $user, array $args): void
    {
        $request = Request::create('/', 'POST', $args);
        $request->setUserResolver(fn () => $user);
        app(Hooks::class)->fire('task.saved', $task, $request);
    }

    /** deleted_at + the day trash:purge removes it, for *_list_deleted. */
    public static function trashInfo(Model $m): array
    {
        return [
            'deleted_at' => $m->deleted_at->toIso8601String(),
            'purged_on' => $m->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ];
    }

    public static function changed(): void
    {
        Realtime::broadcast(new TasksChanged((int) CurrentFamily::id()));
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
