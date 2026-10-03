<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Modules\Calendar\CalendarEvent;

// Shared by Calendar tests (EventsTest + later Calendar test files in this task's series).
if (! function_exists('calendarFamily')) {
    function calendarFamily(array $modules = ['calendar', 'tasks', 'contacts']): array
    {
        $users = User::factory()->count(2)->create();
        $family = Family::factory()->create(['owner_id' => $users[0]->id]);
        foreach ($users as $i => $u) {
            $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
        }
        session(['active_family_id' => $family->id]);
        CurrentFamily::set($family->id);
        test()->artisan('modules:migrate')->assertExitCode(0);
        foreach ($modules as $m) {
            app(ModuleRegistry::class)->enable($m, $family->id);
        }

        return [$family, $users];
    }
}

if (! function_exists('trip')) {
    function trip(User $by, array $over = []): CalendarEvent
    {
        return CalendarEvent::createFor($by, CalendarEvent::validated($over + [
            'title' => 'Grecia', 'start_date' => '2026-07-17', 'end_date' => '2026-07-25',
        ]));
    }
}
