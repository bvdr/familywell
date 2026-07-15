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
    app(ModuleRegistry::class)->markInstalled('hello', '1.0.0');

    $this->actingAs($user)->get('/m/hello')->assertNotFound();
});

it('serves a module route when enabled for the active family', function () {
    [$user, $family] = familyUser();
    $registry = app(ModuleRegistry::class);
    $registry->markInstalled('hello', '1.0.0');
    $registry->enable('hello', $family->id);

    $this->actingAs($user)->get('/m/hello')
        ->assertOk()
        ->assertJson(['message' => 'Hello']);
});
