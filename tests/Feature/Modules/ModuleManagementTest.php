<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Inertia\Testing\AssertableInertia as Assert;

function moduleAdmin(): array
{
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);

    return [$admin, $family];
}

it('lists installed modules with enabled state', function () {
    [$admin] = moduleAdmin();
    $this->actingAs($admin)->get('/family/modules')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('family/modules')
            ->where('canManage', true)
            ->has('catalog'));
});

it('lets an admin enable and disable a module', function () {
    [$admin, $family] = moduleAdmin();
    $registry = app(ModuleRegistry::class);

    $this->actingAs($admin)->post('/family/modules/tasks/enable')->assertRedirect();
    expect($registry->isEnabled('tasks', $family->id))->toBeTrue();

    $this->actingAs($admin)->delete('/family/modules/tasks')->assertRedirect();
    expect($registry->isEnabled('tasks', $family->id))->toBeFalse();
});

it('forbids a non-admin from managing modules', function () {
    [, $family] = moduleAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->post('/family/modules/tasks/enable')->assertForbidden();
});

it('404s enabling a module that is not installed', function () {
    [$admin] = moduleAdmin();
    $this->actingAs($admin)->post('/family/modules/nope/enable')->assertNotFound();
});
