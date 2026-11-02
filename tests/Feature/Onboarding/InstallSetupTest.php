<?php

use App\Models\Family;
use App\Models\User;

use function Pest\Laravel\actingAs;

// The browser's "Install the app" nudge (InstallNudge -> OnboardingController::startSetup): the onboarding's install
// step, then its alerts step, for anyone in a family, outside the onboarding; remembered on the account so the new
// Home Screen app (separate storage on an iPhone) resumes it; ends Home, never on the plan / recap.

function setupMember(string $role = 'member'): User
{
    $owner = User::factory()->create();
    $family = Family::create(['name' => 'The Riveras', 'owner_id' => $owner->id, 'color' => '#FFDF00']);
    $family->members()->attach($owner, ['role' => 'admin']);
    $user = $role === 'admin' ? $owner : User::factory()->create(['name' => 'Lucia Rivera']);
    if ($role !== 'admin') {
        $family->members()->attach($user, ['role' => $role]);
    }
    session(['active_family_id' => $family->id]);

    return $user;
}

it('takes anyone from the nudge through install and alerts, then Home', function () {
    $user = setupMember();

    actingAs($user)->post('/setup/install')->assertRedirect(route('onboarding.install'));
    expect($user->fresh()->setup_step)->toBe('install');
    actingAs($user->fresh())->get('/onboarding/install')->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding/install')->where('setup', true)->where('step', 'install'));
    // Browsing the app meanwhile isn't blocked (only the installed app is sent back to the step, client side).
    actingAs($user->fresh())->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('setupStep', 'install'));

    actingAs($user->fresh())->post('/onboarding/install', ['via' => 'standalone'])->assertRedirect(route('onboarding.notifications'));
    expect($user->fresh()->setup_step)->toBe('notifications');
    actingAs($user->fresh())->get('/onboarding/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('setup', true));

    actingAs($user->fresh())->post('/onboarding/notifications', ['granted' => true])->assertRedirect(route('dashboard'));
    expect($user->fresh()->setup_step)->toBeNull()->and($user->fresh()->onboarding_step)->toBeNull();
});

it('sends someone still onboarding back to their own step instead', function () {
    $user = setupMember('admin');
    $user->forceFill(['onboarding_step' => 'modules'])->save();

    actingAs($user)->post('/setup/install')->assertRedirect(route('onboarding.modules'));
    expect($user->fresh()->setup_step)->toBeNull();
});

it('lets the computer hand the setup to the phone, which lands on the step', function () {
    $user = setupMember();
    actingAs($user)->post('/setup/install');
    $url = actingAs($user->fresh())->postJson('/onboarding/handoff')->assertOk()->json('url');
    auth()->logout();
    test()->flushSession();

    $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect(route('onboarding.install'));
});

it('does not open the steps for someone who never started the setup', function () {
    $user = setupMember();

    actingAs($user)->get('/onboarding/install')->assertRedirect();
    actingAs($user)->postJson('/onboarding/handoff')->assertForbidden();
});

it('keeps the shared demo account out of it', function () {
    $user = setupMember();
    Family::query()->update(['is_demo' => true]);

    actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('installNudge', false));
    actingAs($user)->post('/setup/install')->assertForbidden();
    expect($user->fresh()->setup_step)->toBeNull();
});
