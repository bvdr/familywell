<?php

namespace Modules\Days\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Days\Day;
use Modules\Notifications\Notify;

/**
 * The reminder for days with one on (design A6): at 9:00 family-local, N days before the day (0 = on the day), to the
 * people who can see it, each in their own language. Runs hourly like birthdays:notify, one zone at a time. Fires
 * only ON the reminder day, so a reminder set after it passed waits for next year instead of arriving late.
 * Idempotent through reminded_on.
 */
class NotifyDays extends Command
{
    public const SEND_HOUR = 9;

    protected $signature = 'days:notify';

    protected $description = 'Push the reminders for days to remember that are due today (in each family\'s time zone).';

    public function handle(ModuleRegistry $modules): int
    {
        $prior = CurrentFamily::id();
        $sent = 0;

        try {
            foreach (Family::query()->distinct()->pluck('timezone') as $zone) {
                $local = now($zone ?? config('app.timezone'));
                if ($local->hour < self::SEND_HOUR) {
                    continue;
                }
                $today = Carbon::parse($local->toDateString());
                $familyIds = Family::query()->where('timezone', $zone)->pluck('id');

                // ponytail: every day with a reminder on in this zone, matched in PHP (daysUntil == remind_days).
                // Fine for thousands of rows; move the match into SQL per distinct remind_days if it grows past that.
                $rows = Day::withoutGlobalScope('family')->with('shareSetting', 'shares')
                    ->whereIn('family_id', $familyIds)
                    ->whereNotNull('remind_days')
                    ->where(fn ($q) => $q->whereNull('reminded_on')->orWhere('reminded_on', '<', $today))
                    ->get();

                foreach ($rows as $day) {
                    if ($day->daysUntil() !== $day->remind_days || ! $modules->isEnabled('days', (int) $day->family_id)) {
                        continue;
                    }
                    CurrentFamily::set((int) $day->family_id);
                    $date = $day->next();
                    foreach (Notify::accessibleUsers($day)->groupBy(fn (User $u) => $u->locale ?? 'en') as $locale => $users) {
                        $body = strtr(Translations::get('days.remind_body', $locale), [':date' => $date->copy()->locale($locale)->translatedFormat('l, M j')]);
                        Notify::push($users, 'days', null, $day->reminderHeadline($day->remind_days, $locale), $body, '/m/days');
                    }
                    $day->forceFill(['reminded_on' => $today])->save();
                    $sent++;
                }
            }
        } finally {
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Days to remember: {$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
