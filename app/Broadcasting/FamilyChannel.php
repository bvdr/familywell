<?php

namespace App\Broadcasting;

use App\Models\Family;
use App\Models\User;

class FamilyChannel
{
    // Only members of a family may subscribe to its private channel (spec 5).
    public function join(User $user, int $familyId): bool
    {
        return Family::where('id', $familyId)
            ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }
}
