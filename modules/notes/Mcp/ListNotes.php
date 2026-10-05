<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Notes\Folder;
use Modules\Notes\Note;

#[IsReadOnly]
class ListNotes extends Tool
{
    protected string $module = 'notes';

    protected string $name = 'notes_list';

    protected string $description = 'Notes this person can see, pinned first then most recently edited, with a short snippet each, plus the family\'s folders. Optionally only one folder.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('Only notes in this folder (name, case-insensitive).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $folderName = trim((string) $request->get('folder', ''));
        $folder = $folderName !== '' ? Folder::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($folderName)])->first() : null;
        if ($folderName !== '' && ! $folder) {
            return Response::error("No folder called \"{$folderName}\".");
        }

        $query = Note::query()->visibleTo($this->user()->id)->with(['folder', 'shareSetting'])
            ->when($folder, fn ($q) => $q->where('folder_id', $folder->id))
            ->orderByDesc('pinned')->orderByDesc('updated_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'notes' => $page['items']->map(fn (Note $n) => NoteData::payload($n, false))->values(),
            'folders' => Folder::query()->orderBy('position')->pluck('name'),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
