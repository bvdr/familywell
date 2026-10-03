<?php

namespace Modules\Notes\Mcp;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use Modules\Notes\Note;

// Shared lookups + payload shapes for the Notes MCP tools. Access follows the web UI: anyone with a role
// on a note may read and edit it (ShareAccess::roleFor, as NotesController does), only its creator may
// delete it. Folders are family-wide.
class NoteData
{
    public static function note(User $user, mixed $id): ?Note
    {
        $note = Note::with('folder')->find((int) $id);

        return $note && app(ShareAccess::class)->roleFor($user, $note) !== null ? $note : null;
    }

    public static function payload(Note $n, bool $withBody = true): array
    {
        $out = [
            'id' => $n->id,
            'title' => $n->title,
            'folder' => $n->folder?->name,
            'pinned' => $n->pinned,
            'visibility' => $n->visibility(),
            'updated_at' => $n->updated_at?->toIso8601String(),
            'url' => rtrim((string) config('app.url'), '/')."/m/notes/{$n->id}",
        ];

        // Lists return a short plain snippet to stay compact; notes_get returns the full markdown body.
        return $out + ($withBody ? ['body' => $n->body] : ['snippet' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $n->body)), 0, 160)]);
    }

    public static function trashInfo(Note $n): array
    {
        return [
            'deleted_at' => $n->deleted_at->toIso8601String(),
            'purged_on' => $n->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ];
    }
}
