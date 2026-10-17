<?php

use App\Models\Family;
use App\Models\User;

it('sets the active family in the session on login', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    signInWithCode($user->email)
        ->assertRedirect(route('dashboard', absolute: false));

    expect(session('active_family_id'))->toBe($family->id);
});
