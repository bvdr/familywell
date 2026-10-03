<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Recipes\Recipe;

class CreateRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_create';

    protected ?string $app = 'recipe';

    protected string $description = 'Save a recipe: title, ingredients (name + qty + unit, grouped if the recipe has sections), steps, and servings and/or the total it makes (yield_amount + yield_unit) so it can be scaled later. Optionally a photo (an image the person attached, a public image URL, or base64). Visible to the whole family by default.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass an image the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = RecipeData::fieldSchema($schema);
        $fields['title'] = $fields['title']->required();

        return $fields + IncomingFile::schema($schema);
    }

    public function handle(Request $request): Response
    {
        $data = Validator::make($request->all(), Recipe::rules())->validate();
        $photo = IncomingFile::from($request, RecipeData::PHOTO_TYPES); // checked before anything is saved
        $recipe = Recipe::createFor($this->user(), $data);
        if ($photo) {
            $recipe->setPhoto($photo);
        }

        return Response::json(RecipeData::payload($recipe->fresh()));
    }
}
