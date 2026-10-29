<?php

namespace Modules\Birthdays\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use App\Support\Translations;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Birthdays\BirthdayDate;
use Modules\Notifications\Notify;

/**
 * The morning "🎂 Veronica turns 22 today" push, to every member of the family (in each member's own
 * language). Runs outside a request, so it walks the families itself and scopes CurrentFamily per
 * family like inventory:check does. Idempotent through `notified_on`: a row stamped today is skipped,
 * so re-running (by hand, or a scheduler retry) never repeats the wish.
 *
 * "Today" and "morning" are the FAMILY's (families.timezone, FamilyClock): it runs hourly and wishes a family once
 * its local clock has reached SEND_FROM_HOUR, so Bucharest hears at 08:00 Bucharest time and a family in
 * Los Angeles on its own birthday, not at 23:00 the day before. Zones are walked one at a time (a handful), not
 * families: one query per zone.
 */
class NotifyBirthdays extends Command
{
    public const SEND_FROM_HOUR = 8;

    protected $signature = 'birthdays:notify';

    protected $description = 'Push a birthday wish to the family for everyone whose birthday is today (in the family\'s time zone).';

    public function handle(ModuleRegistry $modules): int
    {
        $prior = CurrentFamily::id();
        $sent = 0;
        $fallback = config('app.timezone');

        try {
            // null = no zone picked yet: those families live on the app zone (FamilyClock::zone).
            foreach (Family::query()->distinct()->pluck('timezone') as $zone) {
                $local = now($zone ?? $fallback);
                if ($local->hour < self::SEND_FROM_HOUR) {
                    continue; // too early there; a later hourly run catches it
                }
                $today = Carbon::parse($local->toDateString()); // same shape as the date columns (FamilyClock::date)
                $familyIds = Family::query()->where('timezone', $zone)->pluck('id'); // where(col, null) = IS NULL

                // Only the family scope goes (every family, no tenant here); the trash scope must stay.
                $rows = BirthdayDate::withoutGlobalScope('family')->with('person')->on($today)
                    ->whereIn('family_id', $familyIds)
                    ->where(fn ($q) => $q->whereNull('notified_on')->orWhere('notified_on', '<', $today))
                    ->get();

                foreach ($rows as $row) {
                    if (! $row->person || ! $modules->isEnabled('birthdays', (int) $row->family_id)) {
                        continue;
                    }
                    CurrentFamily::set((int) $row->family_id);
                    $family = Family::find($row->family_id);
                    if (! $family) {
                        continue;
                    }
                    // One push per language so each member reads the wish in their own locale.
                    foreach ($family->members->groupBy(fn (User $u) => $u->locale ?? 'en') as $locale => $users) {
                        Notify::push($users, 'birthdays', null, '🎂 '.$row->todayHeadline($locale), Translations::get('birthdays.push_body', $locale), '/m/birthdays');
                    }
                    $row->forceFill(['notified_on' => $today])->save();
                    $sent++;
                }
            }
        } finally {
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Birthdays: {$sent} wish(es) sent.");

        return self::SUCCESS;
    }
}
