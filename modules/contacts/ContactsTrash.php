<?php

namespace Modules\Contacts;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Deleted contacts in Settings > Recently deleted and the MCP trash tools. Contacts are family-wide (no
// per-person sharing), so the family scope is the whole access rule, same as the Contacts page.
class ContactsTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Person::onlyTrashed()->get()->map(fn (Person $p) => Trash::row($p, 'contact', $p->name, 'Contacts'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $person = $type === 'contact' ? Person::onlyTrashed()->find($id) : null;
        if (! $person) {
            return null;
        }
        $person->restore(); // its contact methods, birthday and links never left, so they show again

        return '/m/contacts';
    }
}
