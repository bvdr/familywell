<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;

use function Pest\Laravel\actingAs;

// Steps 5-7 and the tour: notifications, the plan (where billing is on), "your home is ready", the tour on the
// dashboard, then into the app.

function finishingOwner(string $step): array
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create(['name' => 'Ana Rivera']);
    $family = Family::create(['name' => 'The Riveras', 'owner_id' => $user->id, 'color' => '#FFDF00', 'symbol' => 'house']);
    $family->members()->attach($user, ['role' => 'admin']);
    $registry = app(ModuleRegistry::class);
    foreach (['shopping', 'tasks', 'calendar', 'spending'] as $m) {
        $registry->enable($m, $family->id);
    }
    $family->invitations()->create(['email' => 'marco.rivera@example.com', 'role' => 'admin', 'invited_by' => $user->id]);
    $user->forceFill(['onboarding_step' => $step])->save();

    return [$user, $family];
}

it('offers a toggle for each pinned module (all four have push categories), with the invitees in the examples', function () {
    [$user] = finishingOwner('notifications');
    $user->forceFill(['pinned_modules' => ['shopping', 'tasks', 'calendar', 'spending']])->save();

    actingAs($user)->get('/onboarding/notifications')->assertOk()->assertInertia(fn ($page) => $page
        ->component('onboarding/notifications')
        ->where('categories', ['shopping', 'tasks', 'calendar', 'spending'])
        ->where('names', ['Marco']));

    actingAs($user)->post('/onboarding/notifications', ['granted' => true])->assertRedirect(route('onboarding.ready'));
});

it('recaps the family and starts the tour on the dashboard', function () {
    [$user] = finishingOwner('ready');

    actingAs($user)->get('/onboarding/ready')->assertOk()->assertInertia(fn ($page) => $page
        ->component('onboarding/ready')
        ->where('invited', ['Marco'])
        ->where('pinned', ['shopping', 'tasks', 'calendar', 'spending'])
        ->where('plan', null)); // no billing here, so no plan row

    actingAs($user)->post('/onboarding/ready', ['tour' => true])->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBe('tour');

    // The tour runs on the dashboard; any other page brings them back to it.
    actingAs($user->fresh())->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('tour', true));
    actingAs($user->fresh())->get('/family/members')->assertRedirect(route('dashboard'));

    actingAs($user->fresh())->post('/onboarding/tour')->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBeNull();
    actingAs($user->fresh())->get('/family/members')->assertOk();
});

it('can skip the tour straight into the app', function () {
    [$user] = finishingOwner('ready');

    actingAs($user)->post('/onboarding/ready', ['tour' => false])->assertRedirect(route('dashboard'));
    expect($user->fresh()->onboarding_step)->toBeNull();
});
