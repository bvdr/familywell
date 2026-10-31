<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notes\Folder;
use Modules\Notes\Note;

class CreateNote extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_create';

    protected string $description = 'Use this when the user wants to write something down as a note. The note belongs to this person and is visible to the family. The body supports # headings, - lists, [ ] checklists and **bold**. A folder name files it there, creating the folder if needed.';

    protected string $title = 'Create note';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Note title.')->required(),
            'body' => $schema->string()->description('Note text: # headings, - lists, [ ] checklists, **bold**.'),
            'folder' => $schema->string()->description('Folder name (created if it does not exist).'),
            'pinned' => $schema->boolean()->description('true pins it to the top of the notes list.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'folder' => ['nullable', 'string', 'max:255'],
            'pinned' => ['nullable', 'boolean'],
        ]);
        $user = $this->user();
        if (! empty($data['folder'])) {
            $data['folder_id'] = Folder::findOrCreateNamed($data['folder'], $user->id)->id;
        }

        $note = Note::createFor($user, $data);
        if (! empty($data['pinned'])) {
            $note->update(['pinned' => true]);
        }

        return Response::structured(NoteData::payload($note->fresh('folder')));
    }
}
