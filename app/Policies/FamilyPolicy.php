<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\User;

class FamilyPolicy
{
    public function manageMembers(User $user, Family $family): bool
    {
        return $family->members()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    public function manageModules(User $user, Family $family): bool
    {
        return $this->manageMembers($user, $family);
    }
}
