<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

function familyUser(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    return [$user, $family];
}

it('404s a module route when the module is not enabled', function () {
    [$user] = familyUser();
    app(ModuleRegistry::class)->markInstalled('vehicles', '1.0.0');

    $this->actingAs($user)->get('/m/vehicles/widget')->assertNotFound();
});

it('serves a module route when enabled for the active family', function () {
    [$user, $family] = familyUser();
    // Proper install runs the module's migrations (creates its tables) and marks it installed.
    $this->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('vehicles', $family->id);

    $this->actingAs($user)->get('/m/vehicles/widget')->assertOk()->assertJsonStructure(['cars']);
});
