<?php

namespace Modules\Recipes;

use App\Models\EntityVisit;
use App\Models\User;
use App\Support\Add\AddKinds;
use App\Support\Dashboard\DashboardItems;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Engagement;
use App\Support\Guests\GuestableRegistry;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Search\SearchSources;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RecipesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Your modules" tile: how many recipes this person can see.
        DashboardSummaries::register('recipes', fn (int $userId) => ['key' => 'dashboard.sum_recipes', 'params' => ['count' => Recipe::visibleTo($userId)->count()]]);
        // Adding a Recipe counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('recipes', Recipe::class);
        // Classic add's sub line ("last: bananas"): this person's newest recipe they can still see.
        AddKinds::last('recipes', fn (User $u) => Recipe::where('created_by', $u->id)->visibleTo($u->id)->latest('id')->value('title'));

        // MCP: recipes for AI assistants, gated by the `recipes:read` / `recipes:write` scopes.
        McpTools::register('recipes', 'Recipes', [
            Mcp\ListRecipes::class, Mcp\GetRecipe::class, Mcp\ScaleRecipe::class,
            Mcp\CreateRecipe::class, Mcp\UpdateRecipe::class, Mcp\SetPhoto::class,
            Mcp\AddToShopping::class, Mcp\DeleteRecipe::class,
        ]);

        // The "Region & units" preview's weight (App\Support\Region): a real gram amount from a recipe this person
        // can see. Recipes stay in their own units whatever the family picks (metric spoons, no oz conversion).
        app(Hooks::class)->listen('family.region', function (array $region, int $familyId, ?int $userId) {
            if ($userId) {
                foreach (Recipe::where('family_id', $familyId)->visibleTo($userId)->latest()->limit(10)->pluck('ingredients') as $ingredients) {
                    $g = collect($ingredients)->first(fn ($i) => RecipeScale::normUnit($i['unit'] ?? null) === 'g' && ($i['qty'] ?? 0) > 0);
                    if ($g) {
                        $region['samples']['grams'] = $g['qty'];
                        break;
                    }
                }
            }

            return $region;
        });

        // 30-day trash, the core sharing system (`recipe` in share URLs) and the public guest link.
        Trash::source('recipes', RecipesTrash::class);
        Trash::register(Recipe::class);
        ShareableRegistry::register('recipe', Recipe::class);
        GuestableRegistry::register('recipe', Recipe::class);

        // "Pick up where you left off": recipes opened recently, visibility respected.
        DashboardItems::register('recipes', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('recipes', $familyId)) {
                return [];
            }
            $visits = EntityVisit::recentMap('recipe');
            if ($visits->isEmpty()) {
                return [];
            }

            return Recipe::query()->visibleTo($userId)->whereIn('id', $visits->keys())->get()
                ->map(fn (Recipe $r) => [
                    'title' => $r->title,
                    'subtitle' => 'Recipes',
                    'href' => "/m/recipes/{$r->id}",
                    'category' => 'recipes',
                    'icon' => 'chef-hat',
                    'visitedAt' => $visits[$r->id]->toIso8601String(),
                ])->values()->all();
        });

        // H5 search: title or description match, visible recipes only. Meta is the description (a
        // snippet if the match is there, else a plain preview) so the row reads like a recipe card.
        SearchSources::register('recipes', function (string $q, int $userId) {
            $recipes = SearchSources::ilike(Recipe::query()->visibleTo($userId), ['title', 'description'], $q)
                ->orderByDesc('id')->limit(20)->get();

            return $recipes->map(fn (Recipe $r) => [
                'module' => 'recipes', 'title' => $r->title,
                'meta' => $r->description ? SearchSources::snippet($r->description, $q) : Translations::get('nav.recipes'),
                'href' => "/m/recipes/{$r->id}", 'icon' => 'chef-hat',
            ])->values()->all();
        });

        // The photo behind a signed URL, for viewers with no Familywell session: the guest page and the
        // MCP widget inside Claude/ChatGPT. The signature authorizes it; no family or module gate needed.
        Route::middleware(['web', 'signed', 'throttle:120,1'])
            ->get('/recipes/photo/{recipe}', [RecipesController::class, 'signedPhoto'])
            ->whereNumber('recipe')
            ->name('recipes.photo.signed');

        Route::middleware(['web', 'auth', 'family', 'module:recipes'])
            ->prefix('m/recipes')
            ->name('recipes.')
            ->group(function () {
                Route::get('/', [RecipesController::class, 'index'])->name('index');
                Route::get('/new', [RecipesController::class, 'create'])->name('create');
                Route::post('/', [RecipesController::class, 'store'])->name('store');
                Route::get('/{recipe}', [RecipesController::class, 'show'])->name('show');
                Route::get('/{recipe}/edit', [RecipesController::class, 'edit'])->name('edit');
                Route::patch('/{recipe}', [RecipesController::class, 'update'])->name('update');
                Route::delete('/{recipe}', [RecipesController::class, 'destroy'])->name('destroy');
                Route::post('/{recipe}/photo', [RecipesController::class, 'uploadPhoto'])->name('photo.upload');
                Route::get('/{recipe}/photo', [RecipesController::class, 'photo'])->name('photo');
                Route::delete('/{recipe}/photo', [RecipesController::class, 'deletePhoto'])->name('photo.delete');
            });
    }
}
