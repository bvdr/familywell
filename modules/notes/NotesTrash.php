<?php

namespace Modules\Notes;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Notes trash: deleted notes the person can see.
class NotesTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Note::onlyTrashed()->visibleTo($user->id)->with('folder')->get()
            ->map(fn (Note $n) => Trash::row($n, 'note', trim($n->title) !== '' ? $n->title : 'Untitled note', $n->folder?->name));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $note = $type === 'note' ? Note::onlyTrashed()->find($id) : null;
        if (! $note || app(ShareAccess::class)->roleFor($user, $note) === null) {
            return null;
        }
        $note->restore();

        return "/m/notes/{$note->id}";
    }
}
