<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Notes\Folder;
use Modules\Notes\Note;

class CreateNote extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_create';

    protected string $description = 'Create a note owned by this person, visible to the family. Body is markdown-lite (# headings, - lists, [ ] checklists, **bold**). Passing a folder name files it there, creating the folder if needed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string(),
            'folder' => $schema->string()->description('Folder name (created if it does not exist).'),
            'pinned' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
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

        return Response::json(NoteData::payload($note->fresh('folder')));
    }
}
