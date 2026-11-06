<?php

namespace Modules\Days;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Removed days in Settings > Recently deleted, only the ones this person could see.
class DaysTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Day::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (Day $d) => Trash::row($d, 'day', $d->title, 'Days to remember'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $day = $type === 'day' ? Day::onlyTrashed()->visibleTo($user->id)->find($id) : null;
        if (! $day) {
            return null;
        }
        $day->restore();

        return '/m/days';
    }
}
