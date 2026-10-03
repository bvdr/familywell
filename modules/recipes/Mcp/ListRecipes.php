<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Recipes\Recipe;

#[IsReadOnly]
class ListRecipes extends Tool
{
    protected string $module = 'recipes';

    protected string $name = 'recipes_list';

    protected ?string $app = 'recipe';

    protected string $description = 'The family\'s recipes (title, photo, tags, servings, total time), A to Z. Optionally search: matches the title, a tag, or an ingredient ("what can I make with leeks").';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Part of a title, a tag, or an ingredient name.'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $q = mb_strtolower(trim((string) $request->get('query', '')));
        // ponytail: filtered in PHP (tags + ingredient names are JSON), fine for a family's cookbook.
        $recipes = Recipe::query()->visibleTo($this->user()->id)->orderBy('title')->get()
            ->when($q !== '', fn ($c) => $c->filter(fn (Recipe $r) => str_contains(mb_strtolower($r->title), $q)
                || collect($r->tags ?? [])->contains(fn ($t) => str_contains(mb_strtolower($t), $q))
                || collect($r->ingredients ?? [])->contains(fn ($i) => str_contains(mb_strtolower($i['name']), $q))))
            ->values();
        $page = $this->page($recipes, $request);

        return Response::json([
            'recipes' => $page['items']->map(fn (Recipe $r) => RecipeData::summary($r))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
