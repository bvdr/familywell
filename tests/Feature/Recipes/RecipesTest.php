<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Recipes\Recipe;
use Modules\Recipes\RecipeScale;
use Modules\Shopping\ShoppingList;

use function Pest\Laravel\actingAs;

function recipeFamily(array $modules = ['recipes']): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    CurrentFamily::set($family->id);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ($modules as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }

    return [$family, $users];
}

function pancakes(User $by): Recipe
{
    return Recipe::createFor($by, [
        'title' => 'Pancakes', 'servings' => 4, 'yield_amount' => 1200, 'yield_unit' => 'g',
        'ingredients' => [
            ['name' => 'flour', 'qty' => 500, 'unit' => 'g'],
            ['name' => 'milk', 'qty' => 0.3, 'unit' => 'l'],
            ['name' => 'eggs', 'qty' => 3, 'unit' => 'pcs'],
            ['name' => 'salt', 'qty' => null, 'unit' => null],
        ],
        'steps' => ['Mix.', 'Fry.'],
    ]);
}

it('agrees with the JS calculator on every shared case', function () {
    $cases = json_decode(file_get_contents(base_path('tests/fixtures/recipe-scale.json')), true);
    foreach ($cases['factor'] as $c) {
        $f = RecipeScale::factor($cases['recipe'], $c['target']);
        expect($f === null ? null : round($f, 4))->toEqual($c['expect'], json_encode($c['target']));
    }
    foreach ($cases['scale'] as $c) {
        expect(RecipeScale::ingredient($c['ing'], $c['factor']))->toEqual($c['expect'], json_encode($c));
    }
});

it('creates, shows, edits and trashes a recipe', function () {
    [$family, $users] = recipeFamily();

    actingAs($users[0])->post('/m/recipes', [
        'title' => 'Apple pie', 'servings' => 8, 'tags' => [' dessert ', 'dessert', ''],
        'ingredients' => [['name' => 'apples', 'qty' => 1.5, 'unit' => 'kg', 'group' => 'Filling'], ['name' => 'cinnamon', 'qty' => null, 'scalable' => false]],
        'steps' => ['  Peel.  ', '', 'Bake.'],
    ])->assertRedirect();
    $pie = Recipe::firstWhere('title', 'Apple pie');
    expect($pie->tags)->toBe(['dessert'])
        ->and($pie->steps)->toBe(['Peel.', 'Bake.'])
        ->and($pie->ingredients[0])->toMatchArray(['group' => 'Filling', 'qty' => 1.5, 'scalable' => true])
        ->and($pie->ingredients[1])->toMatchArray(['qty' => null, 'scalable' => false]);

    actingAs($users[1])->get("/m/recipes/{$pie->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('recipes/recipe')->where('recipe.title', 'Apple pie')->where('canDelete', false)->where('shoppingLists', null));

    actingAs($users[1])->patch("/m/recipes/{$pie->id}", ['title' => 'Apple pie (Ana)'])->assertRedirect();
    expect($pie->fresh()->title)->toBe('Apple pie (Ana)');

    actingAs($users[1])->delete("/m/recipes/{$pie->id}")->assertForbidden(); // creator only
    actingAs($users[0])->delete("/m/recipes/{$pie->id}")->assertRedirect('/m/recipes');
    $this->assertSoftDeleted('recipes_recipes', ['id' => $pie->id]);
});

it('keeps a private recipe away from the rest of the family', function () {
    [$family, $users] = recipeFamily();
    $r = pancakes($users[0]);
    $r->setVisibility('private');

    actingAs($users[1])->get("/m/recipes/{$r->id}")->assertForbidden();
    actingAs($users[1])->get('/m/recipes')->assertInertia(fn ($p) => $p->has('recipes', 0));
    actingAs($users[0])->get('/m/recipes')->assertInertia(fn ($p) => $p->has('recipes', 1));
});

it('stores a photo with its thumbnail and serves it signed to people without a session', function () {
    Storage::fake('r2');
    [$family, $users] = recipeFamily();
    $r = pancakes($users[0]);

    actingAs($users[0])->post("/m/recipes/{$r->id}/photo", [
        'photo' => UploadedFile::fake()->create('p.jpg', 200, 'image/jpeg'),
        'photo_thumb' => UploadedFile::fake()->create('t.jpg', 20, 'image/jpeg'),
    ])->assertRedirect();
    $path = $r->fresh()->photo_path;
    Storage::disk('r2')->assertExists([$path, "{$path}.thumb.jpg"]);

    auth()->logout();
    $this->get($r->fresh()->signedPhotoUrl(true))->assertOk();
    $this->get(route('recipes.photo.signed', ['recipe' => $r->id]))->assertForbidden(); // unsigned

    // Purging the trash frees the files; a soft delete alone keeps them for a restore.
    $r = $r->fresh();
    $r->delete();
    Storage::disk('r2')->assertExists($path);
    $r->forceDelete();
    Storage::disk('r2')->assertMissing([$path, "{$path}.thumb.jpg"]);
});

it('opens the public guest page, behind the password when one is set', function () {
    [$family, $users] = recipeFamily();
    $r = pancakes($users[0]);
    actingAs($users[0])->patch("/guest-links/recipe/{$r->id}", ['enabled' => true])->assertRedirect();
    actingAs($users[0])->patch("/guest-links/recipe/{$r->id}/password", ['password' => 'clătite'])->assertRedirect();
    $token = $r->fresh()->guestLink->token;

    auth()->logout();
    $this->get("/g/{$token}")->assertInertia(fn ($p) => $p->component('guests/locked'));
    $this->post("/g/{$token}/unlock", ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post("/g/{$token}/unlock", ['password' => 'clătite'])->assertRedirect("/g/{$token}");
    $this->get("/g/{$token}")->assertInertia(fn ($p) => $p->component('recipes/guest')->where('recipe.title', 'Pancakes')->has('recipe.ingredients', 4));
});

it('adds the ingredients to a shopping list at a scale, skipping to-taste ones', function () {
    [$family, $users] = recipeFamily(['recipes', 'shopping']);
    $r = pancakes($users[0]);
    $list = ShoppingList::create(['name' => 'Groceries', 'created_by' => $users[0]->id]);

    $added = $r->addToShopping($users[0], $list, 0.8, [0, 1, 2, 3]);
    expect($added)->toBe(['flour', 'milk', 'eggs'])
        ->and($list->items()->orderBy('position')->pluck('quantity', 'name')->all())->toBe(['flour' => '400 g', 'milk' => '240 ml', 'eggs' => '2.5 pcs']);
});
