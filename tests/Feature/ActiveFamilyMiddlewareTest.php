<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('sets the active family for an authenticated request', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    actingAs($user);
    get('/dashboard')->assertOk();

    expect(CurrentFamily::id())->toBe($family->id);
});
