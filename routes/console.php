<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Public demo family: wiped and re-seeded every night at midnight US Eastern (the demo is a US
// household). Runs on the prod `scheduler` worker (schedule:work). Module-owned schedules live in
// their service providers; this one is core, so it lives here. See App\Console\Commands\DemoReset.
Schedule::command('demo:reset')->dailyAt('00:00')->timezone('America/New_York')->withoutOverlapping();
