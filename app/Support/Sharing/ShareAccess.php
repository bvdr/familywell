<?php

namespace App\Support\Sharing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// Resolves a user's effective role on a shareable entity, and the abilities that follow.
// Precedence: an explicit share row wins; otherwise the visibility default applies.
class ShareAccess
{
    private const RANK = ['viewer' => 1, 'editor' => 2, 'owner' => 3];

    /** @return 'owner'|'editor'|'viewer'|null */
    public function roleFor(?User $user, Model $entity): ?string
    {
        if (! $user) {
            return null;
        }

        // Explicit grant always wins (this is also how the creator gets 'owner').
        $explicit = $entity->shares()->where('user_id', $user->id)->value('role');
        if ($explicit) {
            return $explicit;
        }

        // Default from visibility. `family` => any member of the entity's family is an editor.
        if ($entity->visibility() === 'family') {
            $familyId = $entity->sharingFamilyId();
            if ($familyId && $user->families()->whereKey($familyId)->exists()) {
                return 'editor';
            }
        }

        return null; // invited / private with no explicit share, or outside the family
    }

    public function canView(?User $user, Model $entity): bool
    {
        return $this->roleFor($user, $entity) !== null;
    }

    public function canEdit(?User $user, Model $entity): bool
    {
        return $this->atLeast($this->roleFor($user, $entity), 'editor');
    }

    public function canManage(?User $user, Model $entity): bool
    {
        return $this->roleFor($user, $entity) === 'owner';
    }

    private function atLeast(?string $role, string $min): bool
    {
        return $role !== null && self::RANK[$role] >= self::RANK[$min];
    }
}
