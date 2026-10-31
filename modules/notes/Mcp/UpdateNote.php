<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Notes\Folder;

#[IsIdempotent]
#[IsDestructive]
class UpdateNote extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_update';

    protected string $description = 'Use this when the user wants to edit a note: title, body, folder or pinned. `body` replaces the whole text. A folder name files it there (created if needed); null takes it out of its folder. Only the fields you pass change.';

    protected string $title = 'Update note';

    public function schema(JsonSchema $schema): array
    {
        return [
            'note_id' => $schema->integer()->description('Note id, from notes_list or notes_search.')->required(),
            'title' => $schema->string()->description('New title.'),
            'body' => $schema->string()->description('The complete new text (replaces the old body).'),
            'folder' => $schema->string()->description('Folder name (created if needed); null takes it out of its folder.')->nullable(),
            'pinned' => $schema->boolean()->description('true pins it, false unpins it.'),
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
