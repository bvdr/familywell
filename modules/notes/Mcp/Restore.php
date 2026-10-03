<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Notes\Note;

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
        $note = Note::onlyTrashed()->find((int) $request->get('note_id'));
        if (! $note || app(ShareAccess::class)->roleFor($this->user(), $note) === null) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        $note->restore();

        return Response::json(['restored' => true] + NoteData::payload($note->fresh('folder'), false));
    }
}
