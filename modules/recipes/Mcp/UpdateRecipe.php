<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Recipes\Recipe;

#[IsIdempotent]
#[IsDestructive]
class UpdateRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_update';

    protected ?string $app = 'recipe';

    protected string $description = 'Use this when the user wants to change a saved recipe. Only the fields you pass change; pass null to clear one. `ingredients` and `steps` replace the whole list, so send the complete list (read it with recipes_get). To save a scaled version, pass the scaled ingredients and the new servings or yield.';

    protected string $title = 'Update recipe';

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->description('Recipe id, from recipes_list.')->required(), ...RecipeData::fieldSchema($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $recipe = RecipeData::recipe($this->user(), $request->get('recipe_id'));
        if (! $recipe) {
            return $this->notFound('Recipe');
        }
        $data = Validator::make($request->except('recipe_id'), Recipe::rules(creating: false))->validate();
        $recipe->update(Recipe::clean($data));

        return Response::structured(RecipeData::payload($recipe->fresh()));
    }
}
