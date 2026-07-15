<?php

use App\Models\User;
use function Pest\Laravel\post;

it('creates a family with the registrant as admin', function () {
    post('/register', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'ada@example.com')->first();

    expect($user->families)->toHaveCount(1)
        ->and($user->families->first()->owner_id)->toBe($user->id)
        ->and($user->families->first()->pivot->role)->toBe('admin');
});
