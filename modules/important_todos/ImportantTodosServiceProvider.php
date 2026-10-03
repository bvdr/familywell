<?php

namespace Modules\ImportantTodos;

use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Tasks\Task;

/**
 * Extends the `task` primitive entirely through the three hook kinds the tasks module publishes —
 * data (`task.serialize`), behavior-save (`task.saved`), behavior-query (`task.query`). It touches
 * no tasks-module code; deleting this folder removes the feature without a trace. This is the
 * acceptance test for the primitives/plugins design (docs/primitives-and-plugins.md §11).
 *
 * IMPORTANT: every module provider boots for every request regardless of per-family enablement, and
 * these listeners fire on ordinary (ungated) task requests. So each one MUST self-gate on isEnabled —
 * the same pattern the tasks module's DashboardItems closures use. Without the gate, the listeners
 * would run for families that never enabled the module, and would query `important_todos_flags` before
 * `modules:migrate` has created it (a family can't enable an un-installed module, so the gate is false
 * until then). PERF NOTE: `task.serialize` runs per task, so a production build would memoize the
 * enablement check + batch-load the family's flags once per request instead of a query per task.
 */
class ImportantTodosServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        // DATA — add `important` to every task payload the tasks module serializes (top-level + subs).
        $hooks->listen('task.serialize', function (array $payload, Task $task) {
            if (! $this->enabledHere()) {
                return $payload;
            }
            $payload['important'] = (bool) (ImportantFlag::where('task_id', $task->id)->value('important') ?? false);

            return $payload;
        });

        // BEHAVIOR (save) — persist the flag from the request when a task is created or edited. Only
        // act when the request actually carries `important`, so a plain toggle/rename leaves it alone.
        $hooks->listen('task.saved', function (Task $task, Request $request) {
            if (! $this->enabledHere() || ! $request->has('important')) {
                return;
            }
            ImportantFlag::updateOrCreate(
                ['task_id' => $task->id],
                ['important' => $request->boolean('important')],
            );
        });

        // MCP — offer `important` on the Tasks create/update tools (the tools then fire task.saved with it,
        // which the listener above persists). Self-gated like every listener here.
        $hooks->listen('task.mcp.schema', function (array $props, $schema) {
            if (! $this->enabledHere()) {
                return $props;
            }

            return $props + ['important' => $schema->boolean()->description('Star the task as important (important tasks sort first).')];
        });

        // BEHAVIOR (query) — sort important tasks first. Left-join our own table and add a primary
        // DESC order; the tasks controller applies `position` as the secondary order afterwards, so
        // the visible result is "important first, then the usual order". `select` keeps the Task model
        // clean (no joined columns leak onto it).
        $hooks->listen('task.query', function (Builder $query) {
            if (! $this->enabledHere()) {
                return $query;
            }

            return $query
                ->leftJoin('important_todos_flags', 'important_todos_flags.task_id', '=', 'tasks_tasks.id')
                ->select('tasks_tasks.*')
                // A CASE, not `orderByDesc('important')`: the left-join leaves NULL for tasks with no
                // flag row, and Postgres sorts NULLs FIRST on DESC — which would push important tasks
                // below un-flagged ones. CASE maps important→1, everything else (false or NULL)→0, and
                // works the same on Postgres/MySQL/SQLite.
                ->orderByRaw('CASE WHEN important_todos_flags.important THEN 1 ELSE 0 END DESC');
        });
    }

    // True only when the current family has this module enabled. False for everyone else (and before
    // install/migrate), which is what keeps disabled families untouched and pre-migration pages safe.
    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('important_todos', (int) $familyId);
    }
}
