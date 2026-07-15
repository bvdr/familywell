<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

it('migrates modules, installs them, and records a family-scoped ping end to end', function () {
    $this->artisan('modules:migrate')->assertExitCode(0);

    $registry = app(ModuleRegistry::class);
    expect($registry->isInstalled('hello'))->toBeTrue();

    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    $registry->enable('hello', $family->id);

    $this->actingAs($user)->get('/m/hello')->assertOk();

    expect(DB::table('hello_pings')->where('family_id', $family->id)->count())->toBe(1);
});
