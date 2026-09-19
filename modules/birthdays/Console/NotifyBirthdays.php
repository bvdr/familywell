<?php

namespace Modules\Birthdays\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
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
 */
class NotifyBirthdays extends Command
{
    protected $signature = 'birthdays:notify';

    protected $description = 'Push a birthday wish to the family for everyone whose birthday is today.';

    public function handle(ModuleRegistry $modules): int
    {
        $today = Carbon::today();
        $prior = CurrentFamily::id();
        $sent = 0;

        $rows = BirthdayDate::withoutGlobalScopes()->with('person')->on($today)
            ->where(fn ($q) => $q->whereNull('notified_on')->orWhere('notified_on', '<', $today))
            ->get();

        try {
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
        } finally {
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Birthdays: {$sent} wish(es) sent.");

        return self::SUCCESS;
    }
}
