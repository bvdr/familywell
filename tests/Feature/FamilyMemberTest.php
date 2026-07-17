<?php

use App\Models\Family;
use App\Models\User;

use function Pest\Laravel\actingAs;

// Adding members is now the invitation flow (see Family/FamilyManagementTest). This covers the
// members page and member removal.
function familyWithAdmin(): array
{
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    return [$admin, $family];
}

it('renders the members page', function () {
    [$admin] = familyWithAdmin();

    actingAs($admin)->get('/family/members')->assertOk();
});

it('lets an admin remove a member', function () {
    [$admin, $family] = familyWithAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    actingAs($admin)->delete(route('family.members.destroy', $member->id))->assertRedirect();
    expect($family->fresh()->members)->toHaveCount(1);
});

it('will not remove the family owner', function () {
    [$admin, $family] = familyWithAdmin();

    actingAs($admin)->delete(route('family.members.destroy', $admin->id))->assertForbidden();
});

it('forbids a non-admin from removing members', function () {
    [$admin, $family] = familyWithAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    actingAs($member)->delete(route('family.members.destroy', $admin->id))->assertForbidden();
});
