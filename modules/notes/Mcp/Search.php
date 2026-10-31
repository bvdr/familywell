<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Notes\Note;

#[IsReadOnly]
class Search extends Tool
{
    protected string $module = 'notes';

    protected string $name = 'notes_search';

    protected string $description = 'Use this when the user is looking for a note by something written in it. Finds notes this person can see whose title or body contains the text (case-insensitive). Read-only.';

    protected string $title = 'Search notes';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to find in the title or body.')->required(),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $q = trim((string) $request->validate(['query' => ['required', 'string', 'max:255']])['query']);
        $query = Note::query()->visibleTo($this->user()->id)->with(['folder', 'shareSetting'])
            ->where(fn ($w) => $w->whereLike('title', "%{$q}%")->orWhereLike('body', "%{$q}%"))
            ->orderByDesc('updated_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'notes' => $page['items']->map(fn (Note $n) => NoteData::payload($n, false))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
