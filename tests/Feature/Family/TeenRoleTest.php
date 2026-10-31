<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

// The Teen role from the onboarding's invites ("their lists, tasks and calendar"): only those modules, and never
// managing members, modules or billing.

function teenFamily(): array
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    $owner = User::factory()->create();
    $teen = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $owner->id]);
    $family->members()->attach($owner, ['role' => 'admin']);
    $family->members()->attach($teen, ['role' => 'teen']);
    app(ModuleRegistry::class)->enableAll($family->id);

    return [$family, $owner, $teen];
}

it('shows a teen only shopping, tasks and calendar', function () {
    [$family, , $teen] = teenFamily();

    actingAs($teen)->withSession(['active_family_id' => $family->id])->get('/m/shopping')->assertOk();
    actingAs($teen)->withSession(['active_family_id' => $family->id])->get('/m/spending')->assertStatus(404);
    actingAs($teen)->withSession(['active_family_id' => $family->id])->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('modules.enabled', fn ($ids) => collect($ids)->diff(Family::TEEN_MODULES)->isEmpty()));
});

it('keeps every module for the adults of the same family', function () {
    [$family, $owner] = teenFamily();

    actingAs($owner)->withSession(['active_family_id' => $family->id])->get('/m/spending')->assertOk();
});

it('never lets a teen manage the family', function () {
    [$family, , $teen] = teenFamily();

    expect($teen->can('manageMembers', $family))->toBeFalse()
        ->and($teen->can('manageBilling', $family))->toBeFalse()
        ->and($teen->can('manageModules', $family))->toBeFalse();
});

it('invites a Teen from the onboarding as a teen', function () {
    Mail::fake();
    [$family, $owner] = teenFamily();
    $owner->forceFill(['onboarding_step' => 'invite'])->save();

    actingAs($owner)->withSession(['active_family_id' => $family->id])
        ->post('/onboarding/invite', ['invites' => [['email' => 'lucia@example.com', 'role' => 'teen']]])->assertRedirect();

    expect($family->invitations()->where('email', 'lucia@example.com')->value('role'))->toBe('teen');
});
