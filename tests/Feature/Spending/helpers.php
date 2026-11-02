<?php

// Helpers shared by several test files. In their own file (require_once) so a parallel run (pest --parallel),
// where a worker may never load the file that used to hold them, still finds them.

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

// A family with three members, the spending module installed and enabled, and it active in session.
function spendingFamily(int $members = 3): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('spending', $family->id);

    return [$family, $users];
}
