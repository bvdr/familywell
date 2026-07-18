<?php

use App\Mail\FamilyInvitationMail;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

// A family with an admin (owner) and a plain member; the family is active in session.
function managedFamily(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);

    return [$family, $admin, $member];
}

it('lets an admin edit the family details', function () {
    [$family, $admin] = managedFamily();

    actingAs($admin)->patch('/family', [
        'name' => 'The Riveras', 'description' => 'Home base', 'color' => '#D4943A', 'emoji' => '🏡',
    ])->assertRedirect();

    expect($family->fresh())->name->toBe('The Riveras')->emoji->toBe('🏡')->color->toBe('#D4943A');
});

it('accepts any hex color and any emoji, not just the presets', function () {
    [$family, $admin] = managedFamily();

    actingAs($admin)->patch('/family', [
        'name' => 'Wolfpack', 'color' => '#7B2D8E', 'emoji' => '🐺',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($family->fresh())->color->toBe('#7B2D8E')->emoji->toBe('🐺');

    // ...but plain text is not an emoji, and a non-hex color is rejected.
    actingAs($admin)->patch('/family', ['name' => 'X', 'color' => '#7B2D8E', 'emoji' => 'hi'])->assertSessionHasErrors('emoji');
    actingAs($admin)->patch('/family', ['name' => 'X', 'color' => 'blue', 'emoji' => '🐺'])->assertSessionHasErrors('color');
});

it('forbids a non-admin from editing the family', function () {
    [$family, , $member] = managedFamily();

    actingAs($member)->patch('/family', ['name' => 'Hacked', 'color' => '#2D5E3A', 'emoji' => '🌿'])->assertForbidden();
});

it('sends an invitation email and lists it as pending', function () {
    Mail::fake();
    [$family, $admin] = managedFamily();

    actingAs($admin)->post('/family/invitations', ['email' => 'sam@example.com', 'role' => 'member'])->assertRedirect();

    $invite = FamilyInvitation::first();
    expect($invite->email)->toBe('sam@example.com')->and($invite->family_id)->toBe($family->id);
    Mail::assertSent(FamilyInvitationMail::class, fn ($m) => $m->hasTo('sam@example.com'));
});

it('rejects inviting an existing member', function () {
    Mail::fake();
    [$family, $admin, $member] = managedFamily();

    actingAs($admin)->post('/family/invitations', ['email' => $member->email, 'role' => 'member'])
        ->assertSessionHasErrors('email');
    Mail::assertNothingSent();
});

it('lets an authenticated user accept an invite and join', function () {
    [$family, $admin] = managedFamily();
    $invite = $family->invitations()->create(['email' => 'newbie@example.com', 'role' => 'admin', 'invited_by' => $admin->id]);
    $newbie = User::factory()->create(['email' => 'newbie@example.com']);

    actingAs($newbie)->get("/invite/{$invite->token}")->assertOk();
    actingAs($newbie)->post("/invite/{$invite->token}")->assertRedirect(route('dashboard'));

    expect($newbie->fresh()->families)->toHaveCount(1)
        ->and($newbie->families->first()->pivot->role)->toBe('admin');
    expect(FamilyInvitation::count())->toBe(0); // consumed
});

it('sends a guest to register and remembers the invite', function () {
    [$family, $admin] = managedFamily();
    $invite = $family->invitations()->create(['email' => 'guest@example.com', 'role' => 'member', 'invited_by' => $admin->id]);

    $this->get("/invite/{$invite->token}")
        ->assertRedirect(route('register'))
        ->assertSessionHas('invite_token', $invite->token);
});

it('joins the family after registering from an invite', function () {
    [$family, $admin] = managedFamily();
    $invite = $family->invitations()->create(['email' => 'grace@example.com', 'role' => 'member', 'invited_by' => $admin->id]);

    // Follow the guest flow: hitting the link sets the session token, then registration completes it.
    $this->get("/invite/{$invite->token}");
    $this->post('/register', [
        'name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('invite.show', $invite->token));

    // The accept page now joins.
    $grace = User::where('email', 'grace@example.com')->first();
    actingAs($grace)->post("/invite/{$invite->token}")->assertRedirect(route('dashboard'));
    expect($grace->fresh()->families)->toHaveCount(1);
});

it('does not let an admin revoke another family invite', function () {
    [$familyA, $adminA] = managedFamily();
    [$familyB, $adminB] = managedFamily();
    $inviteA = $familyA->invitations()->create(['email' => 'x@example.com', 'role' => 'member', 'invited_by' => $adminA->id]);

    session(['active_family_id' => $familyB->id]);
    actingAs($adminB)->delete("/family/invitations/{$inviteA->id}")->assertNotFound();
});
