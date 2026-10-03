<?php

namespace Modules\Recipes;

use App\Models\User;
use App\Support\Sharing\ShareAccess;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

// Recipes in Settings > Recently deleted and the MCP trash tools.
class RecipesTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Recipe::onlyTrashed()->visibleTo($user->id)->get()
            ->map(fn (Recipe $r) => Trash::row($r, 'recipe', $r->title, 'Recipes'));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $recipe = $type === 'recipe' ? Recipe::onlyTrashed()->find($id) : null;
        if (! $recipe || app(ShareAccess::class)->roleFor($user, $recipe) === null) {
            return null;
        }
        $recipe->restore();

        return "/m/recipes/{$recipe->id}";
    }
}
