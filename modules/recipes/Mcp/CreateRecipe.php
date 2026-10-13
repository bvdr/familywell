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

    protected string $description = 'Save a recipe: title, ingredients (name + qty + unit, grouped if the recipe has sections), steps, and servings and/or the total it makes (yield_amount + yield_unit) so it can be scaled later. Optionally a photo: in ChatGPT the image the person attached (`file`); elsewhere a public https image URL (`file_url`) or the bytes (`file_base64`). Claude cannot pass an image attached in the chat: save the recipe without it and give the person the recipe `url`, where they can add the photo. Visible to the whole family by default.';

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
