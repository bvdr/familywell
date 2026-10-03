<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Notes\Note;

#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'notes';

    protected string $access = 'write';

    protected string $name = 'notes_list_deleted';

    protected string $description = 'Notes in the trash that this person can see, newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $query = Note::onlyTrashed()->visibleTo($this->user()->id)->with(['folder', 'shareSetting'])
            ->orderByDesc('deleted_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::json([
            'deleted' => $page['items']->map(fn (Note $n) => NoteData::payload($n, false) + NoteData::trashInfo($n))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
