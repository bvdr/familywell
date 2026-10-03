<?php

namespace Modules\Recipes;

use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use App\Support\Media\StoredImage;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Shopping\ShoppingList;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipesController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $recipes = Recipe::query()->visibleTo($request->user()->id)->orderBy('title')->get();

        return Inertia::render('recipes/index', [
            'recipes' => $recipes->map(fn (Recipe $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'thumbUrl' => $r->photoUrl(true),
                'tags' => $r->tags ?? [],
                'servings' => $r->servings,
                'totalMinutes' => ($r->prep_minutes || $r->cook_minutes) ? (int) $r->prep_minutes + (int) $r->cook_minutes : null,
                // Ingredient names ride along so the index search finds "what can I make with leeks".
                'ingredientNames' => array_column($r->ingredients ?? [], 'name'),
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('recipes/edit', ['recipe' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $recipe = Recipe::createFor($request->user(), $request->validate(Recipe::rules()));

        return to_route('recipes.show', $recipe);
    }

    public function show(Request $request, Recipe $recipe): Response
    {
        abort_unless($this->access->canView($request->user(), $recipe), 403);
        EntityVisit::record('recipe', $recipe->id); // "Pick up where you left off" on the dashboard

        $family = Family::findOrFail(CurrentFamily::id());
        $canManage = $this->access->canManage($request->user(), $recipe);
        // Lists to send ingredients to; only when this family uses Shopping.
        $lists = app(ModuleRegistry::class)->isEnabled('shopping', $family->id)
            ? ShoppingList::query()->visibleTo($request->user()->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values()
            : null;

        return Inertia::render('recipes/recipe', [
            'recipe' => $recipe->toPayload(),
            'canEdit' => $this->access->canEdit($request->user(), $recipe),
            'canDelete' => $recipe->created_by === $request->user()->id,
            'shoppingLists' => $lists,
            'share' => $this->shareState($recipe, $family, $canManage),
            // Public link (optionally password-gated): the core guest link, read-only for recipes.
            'guestShare' => [
                'type' => 'recipe',
                'id' => $recipe->id,
                'canManage' => $canManage,
                'enabled' => $recipe->guestLink !== null,
                'hasPassword' => (bool) $recipe->guestLink?->hasPassword(),
                'url' => $recipe->guestLink ? route('guest.public', $recipe->guestLink->token) : null,
                'guests' => [],
            ],
        ]);
    }

    public function edit(Request $request, Recipe $recipe): Response
    {
        abort_unless($this->access->canEdit($request->user(), $recipe), 403);

        return Inertia::render('recipes/edit', ['recipe' => $recipe->toPayload()]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $recipe), 403);
        $recipe->update(Recipe::clean($request->validate(Recipe::rules(creating: false))));

        return to_route('recipes.show', $recipe);
    }

    public function destroy(Request $request, Recipe $recipe): RedirectResponse
    {
        abort_unless($recipe->created_by === $request->user()->id, 403); // creator only, like notes
        $recipe->delete(); // trash; the photo is freed when the trash is purged (RecipesServiceProvider)

        return to_route('recipes.index');
    }

    public function uploadPhoto(Request $request, Recipe $recipe): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $recipe), 403);
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],      // client compresses first
            'photo_thumb' => ['nullable', 'image', 'max:4096'], // small client-made variant for cards
        ]);
        $recipe->setPhoto($request->file('photo'), $request->file('photo_thumb'));

        return back();
    }

    public function photo(Request $request, Recipe $recipe): StreamedResponse
    {
        abort_unless($this->access->canView($request->user(), $recipe), 403);
        abort_unless($recipe->photo_path, 404);

        return StoredImage::response($recipe->photo_path, $request->query('v'));
    }

    /** The photo for signed URLs (guest page, MCP widget): the signature IS the authorization. */
    public function signedPhoto(Request $request, int $recipe): StreamedResponse
    {
        $r = Recipe::withoutGlobalScope('family')->findOrFail($recipe);
        abort_unless($r->photo_path, 404);

        return StoredImage::response($r->photo_path, $request->query('v'));
    }

    public function deletePhoto(Request $request, Recipe $recipe): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $recipe), 403);
        $recipe->removePhoto();

        return back();
    }

    // The ShareState the core ShareSheet expects (visibility + per-member roles). Recipes go public through
    // the guest link instead of the plain share link, so no `link` here.
    private function shareState(Recipe $recipe, Family $family, bool $canManage): array
    {
        $visibility = $recipe->visibility();
        $sharesByUser = $recipe->shares->keyBy('user_id');

        return [
            'type' => 'recipe',
            'id' => $recipe->id,
            'visibility' => $visibility,
            'canManage' => $canManage,
            'members' => $family->members->map(function ($m) use ($sharesByUser, $visibility) {
                $explicit = $sharesByUser->get($m->id)?->role;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null),
                    'isOwner' => $explicit === 'owner',
                ];
            })->values(),
            'link' => null,
        ];
    }
}
