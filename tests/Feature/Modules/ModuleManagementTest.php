<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Translations;
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
            ->has('catalog')
            // The page shows a blurb per module so a family knows what enabling it does.
            ->has('catalog.0.description'));
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

// Names and descriptions come from lang/{locale}/modules.json; module.json is the English source.
it('shows module names and descriptions in the user locale', function () {
    [$admin] = moduleAdmin();
    $admin->update(['locale' => 'de']);

    $this->actingAs($admin)->get('/family/modules')
        ->assertInertia(function (Assert $p) {
            $catalog = collect($p->toArray()['props']['catalog'])->keyBy('id');
            expect($catalog['shopping']['name'])->toBe('Einkäufe')
                ->and($catalog['shopping']['description'])->toStartWith('Gemeinsame Einkaufslisten');
        });
});

it('has a translation for every installed module in every locale', function () {
    foreach (app(ModuleRegistry::class)->discover() as $m) {
        foreach (array_keys(Translations::LOCALES) as $locale) {
            $map = Translations::all($locale)['modules'] ?? [];
            expect($map[$m->id]['name'] ?? '')->not->toBe('', "{$m->id} name in {$locale}")
                ->and($map[$m->id]['description'] ?? '')->not->toBe('', "{$m->id} description in {$locale}");
        }
        // English strings mirror module.json, so English users see exactly what the manifest says.
        $en = Translations::all('en')['modules'][$m->id];
        expect($en['name'])->toBe($m->name)->and($en['description'])->toBe($m->description);
    }
});
