<?php

namespace Modules\Calendar\Console;

use Illuminate\Console\Command;
use Modules\Calendar\CalendarSubscription;

/**
 * Refreshes every enabled subscribed calendar (hourly, from CalendarServiceProvider). Runs outside any family, so
 * it reads past the family scope; each subscription carries its own family. One failing calendar only records
 * its error (CalendarSubscription::sync) and the loop moves on.
 */
class SyncSubscriptions extends Command
{
    protected $signature = 'calendar:sync-subscriptions';

    protected $description = 'Refresh subscribed (ICS) calendars';

    public function handle(): int
    {
        $ok = $failed = 0;
        // ponytail: sequential, ~1 request per calendar per hour; queue per calendar if there are ever thousands.
        CalendarSubscription::withoutGlobalScopes()->where('enabled', true)->orderBy('id')
            ->each(function (CalendarSubscription $s) use (&$ok, &$failed) {
                $s->sync() ? $ok++ : $failed++;
            });
        $this->info("Synced {$ok}, failed {$failed}.");

        return self::SUCCESS;
    }
}
