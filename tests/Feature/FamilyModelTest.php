<?php

use App\Models\Family;
use App\Models\User;

it('creates a family owned by a user', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);

    expect($family->name)->not->toBeEmpty()
        ->and($family->owner_id)->toBe($user->id);
});

it('relates members through the pivot with a role', function () {
    $family = Family::factory()->create();
    $member = User::factory()->create();
    $family->members()->attach($member, ['role' => 'member']);

    expect($family->members)->toHaveCount(1)
        ->and($family->members->first()->pivot->role)->toBe('member');
});
