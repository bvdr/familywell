<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $name = 'recipes_get';

    protected ?string $app = 'recipe';

    protected string $description = 'Use this when the user wants to read or cook a recipe. Returns the photo, servings or total yield, times, ingredients (qty null = to taste) and steps. Read-only; to recalculate amounts use recipes_scale.';

    protected string $title = 'Get recipe';

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->description('Recipe id, from recipes_list.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $r = RecipeData::recipe($this->user(), $request->get('recipe_id'));

        return $r
            ? Response::structured(RecipeData::payload($r) + ['shopping_lists' => RecipeData::shoppingLists($this->user())])
            : $this->notFound('Recipe');
    }
}
