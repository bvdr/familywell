<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Notifications\Notify;

#[IsDestructive]
class DeleteNote extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_delete';

    protected string $description = 'Move a note to the trash (restorable for 30 days with notes_restore). Only the person who created the note can delete it.';

    public function schema(JsonSchema $schema): array
    {
        return ['note_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user();
        $note = NoteData::note($user, $request->get('note_id'));
        if (! $note) {
            return $this->notFound('Note');
        }
        if ($note->created_by !== $user->id) {
            return Response::error('Only the person who created this note can delete it.'); // same rule as the web UI
        }

        $title = trim($note->title) !== '' ? $note->title : 'Untitled note';
        Notify::toAccessOf($note, 'notes', $user->id, 'Notes', "{$user->name} deleted note: {$title}", '/m/notes');
        $note->delete();

        return Response::json(['deleted' => true, 'id' => $note->id] + NoteData::trashInfo($note));
    }
}
