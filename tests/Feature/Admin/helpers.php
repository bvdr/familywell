<?php

// Helpers shared by several test files. In their own file (require_once) so a parallel run (pest --parallel),
// where a worker may never load the file that used to hold them, still finds them.

use App\Models\OAuthClient;
use App\Models\User;

function admin(): User
{
    return User::factory()->create(['is_platform_admin' => true]);
}

function adminApiToken(?User $user = null): string
{
    $user ??= User::factory()->create(['name' => 'Bogdan', 'email' => 'boss@example.com', 'is_platform_admin' => true]);
    $token = boardToken($user, adminClient(), OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();

    return $token;
}
