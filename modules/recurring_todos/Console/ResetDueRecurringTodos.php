<?php

namespace Modules\RecurringTodos\Console;

use App\Support\Tenancy\FamilyClock;
use Illuminate\Console\Command;
use Modules\RecurringTodos\RecurringRule;
use Modules\Tasks\Task;

/**
 * The reminder tick: every recurring task whose next occurrence has arrived is reset to "to do"
 * (done = false) and its due date rolled forward to that occurrence. Scheduled hourly (see the
 * provider) and run by the `scheduler` worker's `schedule:work` in production.
 *
 * Runs OUTSIDE any family HTTP request, so `CurrentFamily::id()` is null and the `BelongsToFamily`
 * global scope is inactive — every family's rules are processed in one pass, which is what we want
 * for a global cron.
 */
class ResetDueRecurringTodos extends Command
{
    protected $signature = 'recurring-todos:tick';

    protected $description = 'Reset recurring to-dos whose next occurrence has arrived and roll their date forward';

    public function handle(): int
    {
        // "Arrived" is on each family's own calendar (FamilyClock): fetch everything due by the furthest-ahead zone
        // (UTC+14 is at most a day ahead), then check each against its family's today below. Hourly, so a family's
        // to-do resets within the hour after ITS midnight.
        $rules = RecurringRule::where('next_occurrence', '<=', now()->addDay()->toDateString())->get();

        $reset = 0;
        foreach ($rules as $rule) {
            // withTrashed: a task in the 30-day trash keeps its rule (so a restore brings the repeat back) but
            // is not reset; the rule goes when the task is purged (FK cascade) or is truly missing.
            $task = Task::withTrashed()->find($rule->task_id); // no family scope in console → global lookup
            if (! $task) {
                $rule->delete(); // task gone (belt-and-suspenders; the FK cascade normally handles this)

                continue;
            }
            if ($task->trashed()) {
                continue;
            }
            $today = FamilyClock::date($task->family_id);
            if ($rule->next_occurrence->gt($today)) {
                continue; // not there yet in this family's zone
            }

            // Catch up if the scheduler missed days: step to the latest occurrence on/before today,
            // then park next_occurrence one interval into the future.
            $next = $rule->next_occurrence->copy();
            $due = $next->copy();
            while ($next->lte($today)) {
                $due = $next->copy();
                $next = RecurringRule::advance($next, $rule->interval);
            }

            // saveQuietly: this is a background reset, not a user edit — don't fire model events or
            // the tasks module's notifications/broadcasts.
            $task->forceFill(['due_date' => $due->toDateString(), 'done' => false])->saveQuietly();
            $rule->forceFill(['next_occurrence' => $next->toDateString()])->saveQuietly();
            $reset++;
        }

        $this->info("Recurring reset: {$reset} to-do(s) rolled to their next occurrence.");

        return self::SUCCESS;
    }
}
