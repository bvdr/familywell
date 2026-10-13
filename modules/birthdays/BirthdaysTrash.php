<?php

namespace Modules\Birthdays;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Removed birthdays in Settings > Recently deleted and the MCP trash tools. Only ones whose contact is
// live: a birthday of a deleted contact comes back by restoring the contact (it never left, see Person).
class BirthdaysTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return BirthdayDate::onlyTrashed()->whereHas('person')->with('person')->get()
            ->map(fn (BirthdayDate $b) => Trash::row($b, 'birthday', $b->person->name, 'Birthdays'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $birthday = $type === 'birthday' ? BirthdayDate::onlyTrashed()->whereHas('person')->find($id) : null;
        if (! $birthday) {
            return null;
        }
        $birthday->restore();

        return '/m/birthdays';
    }
}
