<?php

namespace Modules\Recipes\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Modules\Recipes\Recipe;

#[IsOpenWorld]
class CreateRecipe extends Tool
{
    protected string $module = 'recipes';

    protected string $access = 'write';

    protected string $name = 'recipes_create';

    protected ?string $app = 'recipe';

    protected string $description = 'Use this when the user wants to save a recipe: title, ingredients (name, amount, unit, grouped into sections if the recipe has them), steps, and servings and/or the total it makes so it can be scaled later. A photo can be attached (`file`, `file_url` or `file_base64`); when none can be passed, save without it and give the person the recipe `url` to add one. Visible to the whole family by default.';

    protected string $title = 'Create recipe';

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

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = Validator::make($request->all(), Recipe::rules())->validate();
        $photo = IncomingFile::from($request, RecipeData::PHOTO_TYPES); // checked before anything is saved
        $recipe = Recipe::createFor($this->user(), $data);
        if ($photo) {
            $recipe->setPhoto($photo);
        }

        return Response::structured(RecipeData::payload($recipe->fresh()));
    }
}
