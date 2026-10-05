<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Notes\Folder;

#[IsIdempotent]
class UpdateNote extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_update';

    protected string $description = 'Change a note: title, body (replaces the whole body), folder (by name, created if needed; null un-files it), or pinned. Only the fields you pass change.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'note_id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'body' => $schema->string(),
            'folder' => $schema->string()->nullable(),
            'pinned' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'note_id' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:100000'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pinned' => ['sometimes', 'boolean'],
        ]);
        $note = NoteData::note($this->user(), $data['note_id']);
        if (! $note) {
            return $this->notFound('Note');
        }

        $changes = array_intersect_key($data, array_flip(['title', 'body', 'pinned']));
        if (array_key_exists('folder', $data)) {
            $changes['folder_id'] = $data['folder'] ? Folder::findOrCreateNamed($data['folder'], $this->user()->id)->id : null;
        }
        $note->update($changes);

        return Response::structured(NoteData::payload($note->fresh('folder')));
    }
}
