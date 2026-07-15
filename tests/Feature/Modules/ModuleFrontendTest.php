<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

function frontendFamilyUser(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    return [$user, $family];
}

it('404s a module asset when the module is not enabled', function () {
    [$user] = frontendFamilyUser();
    app(ModuleRegistry::class)->markInstalled('hello', '1.0.0');
    $this->actingAs($user)->get('/m/hello/frontend.js')->assertNotFound();
});

it('serves the module asset as javascript when enabled', function () {
    [$user, $family] = frontendFamilyUser();
    $registry = app(ModuleRegistry::class);
    $registry->markInstalled('hello', '1.0.0');
    $registry->enable('hello', $family->id);

    $res = $this->actingAs($user)->get('/m/hello/frontend.js');
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('javascript');
    expect($res->getContent())->toContain('registerSlot');
});
