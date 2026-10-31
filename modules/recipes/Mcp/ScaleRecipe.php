<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ScaleRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $name = 'recipes_scale';

    protected ?string $app = 'recipe';

    protected string $description = 'Use this when the user wants a recipe\'s amounts for more or fewer servings, a different total, or what they have of one ingredient (e.g. "I only have 400 g of flour"). Give ONE of: servings, yield_amount + yield_unit, or have_ingredient + have_amount + have_unit. Returns the factor and every ingredient scaled, with g/kg, ml/l and spoons converted. Does not change the saved recipe.';

    protected string $title = 'Scale recipe';

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->description('Recipe id, from recipes_list.')->required(), ...RecipeData::scaleSchema($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $r = RecipeData::recipe($this->user(), $request->get('recipe_id'));
        if (! $r) {
            return $this->notFound('Recipe');
        }

        return Response::structured(RecipeData::payload($r) + ['scale' => RecipeData::scaled($r, $request->all())]
            + ['shopping_lists' => RecipeData::shoppingLists($this->user())]);
    }
}
