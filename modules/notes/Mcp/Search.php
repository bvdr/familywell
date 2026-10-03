<?php

namespace Modules\Notes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Notes\Note;

#[IsReadOnly]
class Search extends Tool
{
    protected string $module = 'notes';

    protected string $name = 'notes_search';

    protected string $description = 'Find notes this person can see by text in the title or body (case-insensitive).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $q = trim((string) $request->validate(['query' => ['required', 'string', 'max:255']])['query']);
        $query = Note::query()->visibleTo($this->user()->id)->with(['folder', 'shareSetting'])
            ->where(fn ($w) => $w->whereLike('title', "%{$q}%")->orWhereLike('body', "%{$q}%"))
            ->orderByDesc('updated_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::json([
            'notes' => $page['items']->map(fn (Note $n) => NoteData::payload($n, false))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
