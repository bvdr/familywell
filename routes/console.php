<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Public demo family: wiped and re-seeded at the top of every hour. It was nightly at first, but
// visitors vandalize the demo within hours, so hourly keeps it presentable. Runs on the prod
// `scheduler` worker (schedule:work); withoutOverlapping so a slow reset never races the next tick.
// Module-owned schedules live in their service providers; this one is core, so it lives here.
// See App\Console\Commands\DemoReset.
Schedule::command('demo:reset')->hourly()->withoutOverlapping();
