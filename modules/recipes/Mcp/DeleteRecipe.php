<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_delete';

    protected string $description = 'Use this when the user wants to delete a recipe. It goes to the trash and can be restored for 30 days from Settings > Recently deleted in FamilyWell. Only the person who created it can delete it.';

    protected string $title = 'Delete recipe';

    public function schema(JsonSchema $schema): array
    {
        return ['recipe_id' => $schema->integer()->description('Recipe id, from recipes_list.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $recipe = RecipeData::recipe($user, $request->get('recipe_id'));
        if (! $recipe) {
            return $this->notFound('Recipe');
        }
        if ($recipe->created_by !== $user->id) {
            return Response::error('Only the person who created this recipe can delete it.'); // same rule as the app
        }
        $recipe->delete();

        return Response::structured([
            'deleted' => true,
            'id' => $recipe->id,
            'purged_on' => $recipe->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ]);
    }
}
