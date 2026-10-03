<?php

namespace App\Support\Trash;

use App\Models\User;
use Illuminate\Support\Collection;

// A module's view of its own trash (see Trash::source). Implementations apply the same sharing rules as
// the module's web UI: only rows this person could see before they were deleted are listed/restorable.
interface TrashSource
{
    /** @return Collection<int, array> rows built with Trash::row(), any order */
    public static function list(User $user): Collection;

    /** Restore one entry; returns the app path to open it, or null when it is not in this person's trash. */
    public static function restore(User $user, string $type, int $id): ?string;
}
