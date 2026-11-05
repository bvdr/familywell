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
use Modules\Birthdays\BirthdaySettings;
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

                $sent += $this->remind($modules, $familyIds, $today, $local->hour);
            }
        } finally {
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Birthdays: {$sent} wish(es) and reminder(s) sent.");

        return self::SUCCESS;
    }

    /**
     * The advance reminder (design 10b M12): "Lucia turns 12 in a week. That's Wednesday, Oct 28.", at the family's
     * send hour on (birthday - N days), N = the person's remind_days or else the family default. 0 = on the day,
     * which the wish above already is. Fires only ON the reminder day: a reminder saved after it passed skips this
     * year (M7b) rather than arriving late. One query per distinct N (a handful), not one per family or birthday.
     */
    private function remind(ModuleRegistry $modules, $familyIds, Carbon $today, int $hour): int
    {
        // Families whose send hour has come, with their default N (no settings row = the defaults, 3 days at 9).
        $settings = BirthdaySettings::withoutGlobalScope('family')->whereIn('family_id', $familyIds)->get()->keyBy('family_id');
        $defaults = [];
        foreach ($familyIds as $id) {
            $s = $settings[$id] ?? new BirthdaySettings;
            if ($hour >= $s->send_hour) {
                $defaults[$s->remind_days][] = $id;
            }
        }
        $due = array_merge(...array_values($defaults ?: [[]]));
        if (! $due) {
            return 0;
        }
        $overrides = BirthdayDate::withoutGlobalScope('family')->whereIn('family_id', $due)->where('remind_days', '>', 0)->distinct()->pluck('remind_days')->all();

        $sent = 0;
        foreach (array_unique([...array_keys($defaults), ...$overrides]) as $days) {
            if ($days < 1) {
                continue;
            }
            $birthday = $today->copy()->addDays($days);
            $rows = BirthdayDate::withoutGlobalScope('family')->with('person')->on($birthday)
                ->whereIn('family_id', $due)
                ->where(fn ($q) => $q->where('remind_days', $days)->orWhere(fn ($q) => $q->whereNull('remind_days')->whereIn('family_id', $defaults[$days] ?? [])))
                ->where(fn ($q) => $q->whereNull('reminded_on')->orWhere('reminded_on', '<', $today))
                ->get();
            foreach ($rows as $row) {
                if (! $row->person || ! $modules->isEnabled('birthdays', (int) $row->family_id)) {
                    continue;
                }
                CurrentFamily::set((int) $row->family_id);
                foreach (Family::find($row->family_id)?->members->groupBy(fn (User $u) => $u->locale ?? 'en') ?? [] as $locale => $users) {
                    $date = $birthday->copy()->locale($locale)->translatedFormat('l, M j');
                    Notify::push($users, 'birthdays', null, '🎂 '.$row->reminderHeadline($days, $locale),
                        strtr(Translations::get('birthdays.remind_body', $locale), [':date' => $date]), '/m/birthdays');
                }
                $row->forceFill(['reminded_on' => $today])->save();
                $sent++;
            }
        }

        return $sent;
    }
}
