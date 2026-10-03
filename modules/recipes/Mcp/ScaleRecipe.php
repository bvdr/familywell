<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ScaleRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $name = 'recipes_scale';

    protected ?string $app = 'recipe';

    protected string $description = 'Recalculate a recipe\'s ingredients. Give ONE of: servings (e.g. 6), a total amount to make (yield_amount + yield_unit, e.g. 800 g), or what you have of one ingredient (have_ingredient + have_amount + have_unit, e.g. "I only have 400 g of flour"). Returns the factor and every ingredient with scaled_qty / scaled_unit and a ready `line`; g/kg, ml/l and spoons convert, "to taste" and non-scalable items stay as they are. Does not change the recipe.';

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->required(), ...RecipeData::scaleSchema($schema)];
    }

    public function handle(Request $request): Response
    {
        $r = RecipeData::recipe($this->user(), $request->get('recipe_id'));
        if (! $r) {
            return $this->notFound('Recipe');
        }

        return Response::json(RecipeData::payload($r) + ['scale' => RecipeData::scaled($r, $request->all())]
            + ['shopping_lists' => RecipeData::shoppingLists($this->user())]);
    }
}
