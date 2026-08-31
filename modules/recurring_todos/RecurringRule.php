<?php

namespace Modules\RecurringTodos;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per task that repeats. `next_occurrence` is the date on which the task should next
 * "reoccur" — reset to not-done and roll its due date forward. Hangs off the `task` primitive by
 * foreign key (cascade on delete); the tasks module knows nothing about it (post-meta pattern).
 */
class RecurringRule extends Model
{
    use BelongsToFamily;

    protected $table = 'recurring_todos_rules';

    protected $fillable = ['task_id', 'interval', 'next_occurrence', 'family_id'];

    protected $casts = ['next_occurrence' => 'date'];

    /** The intervals the UI offers. Kept in sync with resources/dist/recurring-todos.js. */
    public const INTERVALS = ['daily', 'weekly', 'monthly', 'yearly'];

    /** Advance a date by exactly one interval. Uses no-overflow month math (Jan 31 + 1mo = Feb 28/29). */
    public static function advance(Carbon $date, string $interval): Carbon
    {
        return match ($interval) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'monthly' => $date->copy()->addMonthNoOverflow(),
            'yearly' => $date->copy()->addYear(),
            default => $date->copy()->addWeek(),
        };
    }
}
