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

    protected $fillable = ['task_id', 'interval', 'next_occurrence', 'family_id', 'count_from'];

    protected $casts = ['next_occurrence' => 'date'];

    /**
     * The rhythms the repeat picker offers (design j6QDy4 T4), kept in sync with resources/dist/recurring-todos.js.
     * Besides these, a custom one: "N:unit" ("2:week" = every 2 weeks), see valid().
     */
    public const INTERVALS = ['daily', 'weekdays', 'weekly', 'monthly', 'quarterly', 'yearly'];

    public const COUNT_FROM = ['due', 'done'];

    /** A preset, or a custom "N:day|week|month" with N from 1 to 365. */
    public static function valid(string $interval): bool
    {
        return in_array($interval, self::INTERVALS, true)
            || (preg_match('/^(\d{1,3}):(day|week|month)$/', $interval, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 365);
    }

    /** Advance a date by exactly one interval. Uses no-overflow month math (Jan 31 + 1mo = Feb 28/29). */
    public static function advance(Carbon $date, string $interval): Carbon
    {
        if (preg_match('/^(\d{1,3}):(day|week|month)$/', $interval, $m)) {
            $n = max(1, (int) $m[1]);

            return match ($m[2]) {
                'day' => $date->copy()->addDays($n),
                'week' => $date->copy()->addWeeks($n),
                default => $date->copy()->addMonthsNoOverflow($n),
            };
        }

        return match ($interval) {
            'daily' => $date->copy()->addDay(),
            // Mon-Fri: Friday's next is Monday.
            'weekdays' => $date->copy()->addWeekday(),
            'weekly' => $date->copy()->addWeek(),
            'monthly' => $date->copy()->addMonthNoOverflow(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->addYear(),
            default => $date->copy()->addWeek(),
        };
    }
}
