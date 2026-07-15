<?php

use App\Broadcasting\FamilyChannel;
use App\Models\Family;
use App\Models\User;

function channelFamily(): array
{
    $owner = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $owner->id]);
    $family->members()->attach($owner, ['role' => 'admin']);

    return [$owner, $family];
}

it('authorizes a family member on the private family channel', function () {
    [$owner, $family] = channelFamily();

    expect((new FamilyChannel)->join($owner, $family->id))->toBeTrue();
});

it('rejects a non-member on the private family channel', function () {
    [, $family] = channelFamily();
    $stranger = User::factory()->create();

    expect((new FamilyChannel)->join($stranger, $family->id))->toBeFalse();
});
