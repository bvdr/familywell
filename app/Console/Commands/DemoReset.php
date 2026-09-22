<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Support\Demo\DemoFamily;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Nightly demo reset: wipe the public demo family's data and re-seed it deterministically.
 *
 * SAFETY: the family is located ONLY by `families.is_demo`. Unless exactly one family carries the flag
 * this command aborts and changes nothing — it never guesses (0 flagged: seed first; 2+ flagged: fix the
 * data by hand). Every delete underneath (DemoFamily::wipe) is scoped to that one family_id, so a
 * non-demo family cannot be touched. Safe to run by hand any time: `php artisan demo:reset`.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Wipe the demo family (is_demo = true, exactly one) and re-seed it';

    public function handle(): int
    {
        $count = Family::where('is_demo', true)->count();

        if ($count !== 1) {
            $this->error("Expected exactly one demo family (is_demo = true), found {$count}. Nothing was changed.");
            if ($count === 0) {
                $this->line('Create it first: php artisan db:seed --class=DemoSeeder --force');
            }

            return self::FAILURE;
        }

        $family = DemoFamily::locate();
        $this->laravel->make(DemoSeeder::class)->setContainer($this->laravel)->setCommand($this)->__invoke();
        $this->info("Demo family #{$family->id} ({$family->name}) wiped and re-seeded.");

        return self::SUCCESS;
    }
}
