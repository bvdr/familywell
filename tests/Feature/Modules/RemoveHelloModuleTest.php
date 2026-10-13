<?php

use App\Models\Family;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

// Families that had the deleted `hello` sample module on keep working: its leftover rows go away and the
// family's other modules are untouched.
it('clears every leftover of the removed hello module', function () {
    $family = Family::factory()->create();
    DB::table('modules')->insert(['module_id' => 'hello', 'version' => '1.0.0']);
    DB::table('family_modules')->insert([['family_id' => $family->id, 'module_id' => 'hello', 'enabled' => true], ['family_id' => $family->id, 'module_id' => 'tasks', 'enabled' => true]]);

    (require database_path('migrations/2026_10_13_120000_remove_hello_module.php'))->up();

    expect(app(ModuleRegistry::class)->enabledIds($family->id))->toBe(['tasks'])
        ->and(DB::table('modules')->where('module_id', 'hello')->exists())->toBeFalse();
});
