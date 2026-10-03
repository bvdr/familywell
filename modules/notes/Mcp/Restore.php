<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Notes\NotesTrash;

// Same restore as Settings > Recently deleted (NotesTrash).
class Restore extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_restore';

    protected string $description = 'Bring a note back from the trash.';

    public function schema(JsonSchema $schema): array
    {
        return ['note_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $id = (int) $request->validate(['note_id' => ['required', 'integer']])['note_id'];
        $path = NotesTrash::restore($this->user(), 'note', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        return Response::json(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
