<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

use function Pest\Laravel\actingAs;

// Settings > Home dashboard (design M6b): each person orders and hides their own Home's sections, and the
// dashboard follows. Plus M6's rule that notification categories only show for modules the family has on.

function homeFamily(array $modules = ['spending', 'tasks']): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id, 'name' => 'The Riveras']);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ($modules as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }

    return [$family, $user];
}

it('lists only the sections this family has, in the default order, all on', function () {
    [, $user] = homeFamily();

    actingAs($user)->get('/settings/home')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/home')
        ->where('family', 'The Riveras')
        // birthdays is off for this family, so its section isn't offered.
        ->where('sections', collect(['today', 'week', 'anytime', 'money', 'modules', 'recent'])->map(fn ($id) => ['id' => $id, 'on' => true])->all()));
});

it('saves the order and hidden sections, and the dashboard follows them', function () {
    [, $user] = homeFamily();

    actingAs($user)->patch('/settings/home', ['order' => ['money', 'recent', 'today'], 'hidden' => ['week', 'recent']])->assertRedirect();
    expect($user->fresh()->home_layout)->toBe(['order' => ['money', 'recent', 'today'], 'hidden' => ['week', 'recent']]);

    // Saved ids first, the rest after in the default order; hidden ones left out.
    actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->where('sections', ['money', 'today', 'anytime', 'modules'])
        ->where('recent', []));
});

it('keeps a section of a module that is off hidden, whatever was saved', function () {
    [, $user] = homeFamily(['tasks']);
    $user->forceFill(['home_layout' => ['order' => ['money', 'birthdays', 'today'], 'hidden' => []]])->save();

    actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('sections', ['today', 'week', 'anytime', 'modules', 'recent']));
});

it('resets to the default order with an empty order, keeping what is hidden', function () {
    [, $user] = homeFamily();
    actingAs($user)->patch('/settings/home', ['order' => [], 'hidden' => ['money']])->assertRedirect();

    actingAs($user)->get('/settings/home')->assertInertia(fn ($page) => $page
        ->where('sections.0.id', 'today')
        ->where('sections.3', ['id' => 'money', 'on' => false]));
});

it('rejects unknown or repeated section ids', function (array $body) {
    [, $user] = homeFamily();

    actingAs($user)->patch('/settings/home', $body)->assertSessionHasErrors();
    expect($user->fresh()->home_layout)->toBeNull();
})->with([
    'unknown id' => [['order' => ['today', 'weather'], 'hidden' => []]],
    'repeated id' => [['order' => ['today', 'today'], 'hidden' => []]],
    'unknown hidden' => [['order' => [], 'hidden' => ['nope']]],
    'missing hidden' => [['order' => []]],
]);

it('shows Home dashboard as "n of m on" on Me', function () {
    [, $user] = homeFamily();
    $user->forceFill(['home_layout' => ['order' => [], 'hidden' => ['money']]])->save();

    actingAs($user)->get('/settings')->assertInertia(fn ($page) => $page->where('home', ['on' => 5, 'total' => 6]));
});

it('only offers notification categories of modules the family has on', function () {
    [, $user] = homeFamily(['tasks']);

    actingAs($user)->get('/settings/notifications')->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/notifications')
        ->where('categories', fn ($c) => collect($c)->pluck('id')->sort()->values()->all() === ['sharing', 'tasks']));
});
