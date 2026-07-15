<?php

use App\Models\Family;
use App\Models\User;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

function familyWithAdmin(): array
{
    $admin = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);

    return [$admin, $family];
}

it('lets an admin add a member by email', function () {
    [$admin, $family] = familyWithAdmin();
    User::factory()->create(['email' => 'kid@example.com']);

    actingAs($admin)->post('/family/members', [
        'email' => 'kid@example.com', 'role' => 'member',
    ])->assertRedirect();

    expect($family->fresh()->members)->toHaveCount(2);
});

it('forbids a non-admin from adding members', function () {
    [$admin, $family] = familyWithAdmin();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);
    User::factory()->create(['email' => 'x@example.com']);

    actingAs($member)->post('/family/members', [
        'email' => 'x@example.com', 'role' => 'member',
    ])->assertForbidden();
});
