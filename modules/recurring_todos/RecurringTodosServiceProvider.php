<?php

namespace Modules\RecurringTodos;

use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\RecurringTodos\Console\ResetDueRecurringTodos;
use Modules\Tasks\Task;

/**
 * Makes a task repeat, entirely through the `task` primitive's hooks (no tasks-module edits):
 * - data `task.serialize`  → expose the task's `recurrence` interval to the UI
 * - behavior `task.saved`  → persist the chosen interval + keep next_occurrence in step with the date
 * - a scheduled command    → on each occurrence date, reset the task to "to do" and roll it forward
 *
 * The UI (an interval picker in the editor + a repeat badge on the row) lives in the prebuilt
 * resources/dist/recurring-todos.js. Every listener self-gates on isEnabled (see [[important_todos]]
 * for why that's mandatory).
 */
class RecurringTodosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([ResetDueRecurringTodos::class]);
    }

    public function boot(Hooks $hooks): void
    {
        // Reset due recurring tasks hourly. The schedule is registered app-wide, but the command only
        // ever touches tasks that have a rule row — which exist only for families that enabled +
        // configured recurrence — so it is naturally a no-op otherwise. Run by the `scheduler` worker.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('recurring-todos:tick')->hourly()->withoutOverlapping();
        });

        // DATA — expose the interval (or null) on each task payload so the editor + row can render it.
        $hooks->listen('task.serialize', function (array $payload, Task $task) {
            if (! $this->enabledHere()) {
                return $payload;
            }
            $payload['recurrence'] = RecurringRule::where('task_id', $task->id)->value('interval');

            return $payload;
        });

        // MCP — offer `recurrence` on the Tasks create/update tools (the tools then fire task.saved with it,
        // which the listener below persists). Self-gated like every listener here.
        $hooks->listen('task.mcp.schema', function (array $props, $schema) {
            if (! $this->enabledHere()) {
                return $props;
            }

            return $props + ['recurrence' => $schema->string()->enum([...RecurringRule::INTERVALS, 'none'])
                ->description('Repeat the task: once done it resets to "to do" on the next occurrence. "none" stops repeating. Anchored to the due date (today if none).')];
        });

        // BEHAVIOR (save) — persist the chosen recurrence, and keep next_occurrence anchored to the
        // task's due date. A recurring task keeps its stored done state until the scheduler resets it
        // on the next occurrence (that is the whole point of the "stays done until due again" model).
        $hooks->listen('task.saved', function (Task $task, Request $request) {
            if (! $this->enabledHere()) {
                return;
            }

            // The editor sent a recurrence choice: set, change, or clear the rule.
            if ($request->has('recurrence')) {
                $val = (string) $request->input('recurrence');
                if ($val === '' || $val === 'none' || ! in_array($val, RecurringRule::INTERVALS, true)) {
                    RecurringRule::where('task_id', $task->id)->delete();

                    return;
                }
                // A repeat needs a date to anchor to; default to today if the task has none yet.
                if (! $task->due_date) {
                    $task->due_date = now();
                    $task->saveQuietly();
                }
                RecurringRule::updateOrCreate(
                    ['task_id' => $task->id],
                    ['interval' => $val, 'next_occurrence' => RecurringRule::advance($task->due_date, $val)],
                );

                return;
            }

            // The due date moved on an already-recurring task → recompute the next occurrence from it.
            if ($request->has('due_date') && $task->due_date) {
                $rule = RecurringRule::where('task_id', $task->id)->first();
                if ($rule) {
                    $rule->update(['next_occurrence' => RecurringRule::advance($task->due_date, $rule->interval)]);
                }
            }
        });
    }

    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('recurring_todos', (int) $familyId);
    }
}
