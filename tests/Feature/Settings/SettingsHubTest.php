<?php

use App\Models\Family;
use App\Models\User;

use function Pest\Laravel\actingAs;

// The two settings hubs (design boards 12 "Me" and 11 "You & family"): each row's current value comes from here.

function hubFamily(): array
{
    $user = User::factory()->create(['name' => 'Ana Rivera', 'locale' => 'ro']);
    $other = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id, 'name' => 'The Riveras']);
    $family->members()->attach($user, ['role' => 'admin']);
    $family->members()->attach($other, ['role' => 'member']);
    session(['active_family_id' => $family->id]);

    return [$family, $user];
}

it('opens Me with the person\'s own values and their family', function () {
    [$family, $user] = hubFamily();
    // Counted only for modules the family uses (NotificationCategories::forFamily): Shopping must be on.
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(App\Support\Modules\ModuleRegistry::class)->enable('shopping', $family->id);
    $user->update(['notification_prefs' => ['shopping' => false]]);

    actingAs($user)->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/index')
        ->where('signIn', [])
        ->where('localeName', 'Română')
        ->where('notifications.on', fn ($on) => $on === $page->toArray()['props']['notifications']['total'] - 1)
        ->where('apps', [])
        ->where('family.name', 'The Riveras'));
});

it('opens the family\'s settings with its members and modules', function () {
    [$family, $user] = hubFamily();

    actingAs($user)->get('/settings/family')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/family')
        ->where('family.name', 'The Riveras')
        ->where('isAdmin', true)
        ->where('members', 2)
        ->has('modules.on')
        ->has('modules.total'));
});
