<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetNote extends Tool
{
    protected string $module = 'notes';

    protected string $name = 'notes_get';

    protected ?string $app = 'note';

    protected string $description = 'One note with its full body (markdown-lite: # headings, - lists, [ ] checklists, **bold**).';

    public function schema(JsonSchema $schema): array
    {
        return ['note_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $note = NoteData::note($this->user(), $request->get('note_id'));

        return $note ? Response::structured(NoteData::payload($note)) : $this->notFound('Note');
    }
}
