<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Shopping\ShoppingList;

class AddToShopping extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_add_to_shopping';

    protected string $description = 'Put a recipe\'s ingredients on a shopping list (list_id from shopping_list_lists), at a scale if given (same options as recipes_scale). One item per ingredient with the amount as its quantity; "to taste" items are skipped. Pass `ingredients` (positions in the recipe\'s list, from 0) to add only some, e.g. what the person doesn\'t have. Needs Shopping turned on and this connection allowed to change Shopping.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'recipe_id' => $schema->integer()->required(),
            'list_id' => $schema->integer()->required(),
            'ingredients' => $schema->array()->items($schema->integer())->description('Only these ingredient positions (0-based). Default: all with an amount.'),
            ...RecipeData::scaleSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user();
        $recipe = RecipeData::recipe($user, $request->get('recipe_id'));
        if (! $recipe) {
            return $this->notFound('Recipe');
        }
        // The items land in Shopping, so this needs what changing Shopping itself needs.
        if (! RecipeData::shoppingWritable($user)) {
            return Response::error('This connection can\'t add to shopping lists (Shopping is off for the family, or the connection has no "Read & write" for Shopping).');
        }
        $list = ShoppingList::find((int) $request->get('list_id'));
        if (! $list || $list->archived_at || app(ShareAccess::class)->roleFor($user, $list) === null) {
            return $this->notFound('Shopping list');
        }

        $scale = RecipeData::scaled($recipe, $request->all());
        $only = $request->get('ingredients');
        $added = $recipe->addToShopping($user, $list, $scale['factor'], is_array($only) ? array_map('intval', $only) : null);

        return Response::json([
            'added' => $added,
            'list' => ['id' => $list->id, 'name' => $list->name],
            'scaled_to' => $scale['scaled_to'],
            'url' => RecipeData::url("/m/shopping/lists/{$list->id}"),
        ]);
    }
}
