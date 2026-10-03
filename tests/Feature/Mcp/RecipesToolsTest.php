<?php

use App\Mcp\Apps\Widget;
use Illuminate\Support\Facades\Storage;
use Modules\Recipes\Recipe;
use Modules\Shopping\ShoppingList;

// Recipes over MCP: save a recipe, scale it three ways, put it on a shopping list, give it a photo, and
// the widget that shows it all.

function recipesToken(array $access = ['recipes' => 'write'], array $modules = ['recipes', 'shopping']): array
{
    [$family, $users] = mcpFamily($modules, 2);
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    return [$family, $users, $token];
}

function crepes(string $token): array
{
    [$err, $r] = mcpTool($token, 'recipes_create', [
        'title' => 'Clătite', 'servings' => 4, 'yield_amount' => 1200, 'yield_unit' => 'g', 'tags' => ['dessert'],
        'ingredients' => [
            ['name' => 'flour', 'qty' => 500, 'unit' => 'g'],
            ['name' => 'milk', 'qty' => 0.3, 'unit' => 'l'],
            ['name' => 'eggs', 'qty' => 3, 'unit' => 'buc'],
            ['name' => 'salt'],
        ],
        'steps' => ['Mix everything.', 'Fry thin.'],
    ]);
    expect($err)->toBeFalse();

    return $r;
}

it('saves a recipe and finds it by title, tag or ingredient', function () {
    [, , $token] = recipesToken();
    $r = crepes($token);
    expect($r)->toMatchArray(['title' => 'Clătite', 'servings' => 4, 'visibility' => 'family'])
        ->and($r['ingredients'][3])->toMatchArray(['name' => 'salt', 'qty' => null, 'scalable' => true]);

    foreach (['clăt', 'dessert', 'milk'] as $q) {
        [, $found] = mcpTool($token, 'recipes_list', ['query' => $q]);
        expect(collect($found['recipes'])->pluck('id')->all())->toBe([$r['id']]);
    }
    [, $none] = mcpTool($token, 'recipes_list', ['query' => 'leeks']);
    expect($none['recipes'])->toBe([]);
});

it('scales by servings, by total amount, and by what you have', function () {
    [, , $token] = recipesToken();
    $r = crepes($token);
    $lines = fn (array $res) => collect($res['scale']['ingredients'])->pluck('line')->all();

    [, $six] = mcpTool($token, 'recipes_scale', ['recipe_id' => $r['id'], 'servings' => 6]);
    expect($six['scale']['factor'])->toEqual(1.5)
        ->and($lines($six))->toBe(['750 g flour', '450 ml milk', '4.5 buc eggs', 'salt (to taste)']);

    [, $flour] = mcpTool($token, 'recipes_scale', ['recipe_id' => $r['id'], 'have_ingredient' => 'Flour', 'have_amount' => 400, 'have_unit' => 'g']);
    expect($flour['scale'])->toMatchArray(['factor' => 0.8, 'scaled_to' => '400 g of flour'])
        ->and($lines($flour)[1])->toBe('240 ml milk');

    [, $kg] = mcpTool($token, 'recipes_scale', ['recipe_id' => $r['id'], 'yield_amount' => 2.4, 'yield_unit' => 'kg']);
    expect($kg['scale']['factor'])->toEqual(2)->and($lines($kg)[0])->toBe('1 kg flour');

    // Can't be computed -> explained, not guessed.
    [$err, $msg] = mcpTool($token, 'recipes_scale', ['recipe_id' => $r['id'], 'have_ingredient' => 'flour', 'have_amount' => 2, 'have_unit' => 'tbsp']);
    expect($err)->toBeTrue()->and($msg)->toContain('units don');
    [$err, $msg] = mcpTool($token, 'recipes_scale', ['recipe_id' => $r['id'], 'have_ingredient' => 'butter', 'have_amount' => 2]);
    expect($err)->toBeTrue()->and($msg)->toContain('no ingredient called');
});

it('puts scaled ingredients on a shopping list only when the connection may change Shopping', function () {
    [$family, $users, $token] = recipesToken(['recipes' => 'write', 'shopping' => 'write']);
    $r = crepes($token);
    $list = ShoppingList::create(['name' => 'Groceries', 'created_by' => $users[0]->id, 'family_id' => $family->id]);

    [, $got] = mcpTool($token, 'recipes_get', ['recipe_id' => $r['id']]);
    expect($got['shopping_lists'])->toBe([['id' => $list->id, 'name' => 'Groceries']]);

    [$err, $out] = mcpTool($token, 'recipes_add_to_shopping', ['recipe_id' => $r['id'], 'list_id' => $list->id, 'servings' => 2, 'ingredients' => [0, 2, 3]]);
    expect($err)->toBeFalse()->and($out['added'])->toBe(['flour', 'eggs'])
        ->and($list->items()->orderBy('position')->pluck('quantity', 'name')->all())->toBe(['flour' => '250 g', 'eggs' => '1.5 buc']);

    // Recipes-only connection: no lists offered, and the add is refused.
    $recipesOnly = mcpConnect($users[0], $family->id, ['recipes' => 'write', 'shopping' => 'read'])['access_token'];
    [, $got] = mcpTool($recipesOnly, 'recipes_get', ['recipe_id' => $r['id']]);
    expect($got['shopping_lists'])->toBeNull();
    [$err, $msg] = mcpTool($recipesOnly, 'recipes_add_to_shopping', ['recipe_id' => $r['id'], 'list_id' => $list->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('can\'t add to shopping lists');
});

it('sets a photo from base64 and returns a signed URL that works without a session', function () {
    Storage::fake('r2');
    [, , $token] = recipesToken();
    $r = crepes($token);
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

    [$err, $out] = mcpTool($token, 'recipes_set_photo', ['recipe_id' => $r['id'], 'file_base64' => $png, 'file_name' => 'clatite.png']);
    expect($err)->toBeFalse();
    auth()->forgetGuards();
    $this->get($out['photo_url'])->assertOk();

    [$err] = mcpTool($token, 'recipes_set_photo', ['recipe_id' => $r['id'], 'file_base64' => base64_encode('%PDF-1.4 not an image'), 'file_name' => 'x.pdf']);
    expect($err)->toBeTrue();
});

it('lets only the creator delete, into the trash', function () {
    [$family, $users, $token] = recipesToken();
    $r = crepes($token);
    $other = mcpConnect($users[1], $family->id, ['recipes' => 'write'])['access_token'];

    [$err, $msg] = mcpTool($other, 'recipes_delete', ['recipe_id' => $r['id']]);
    expect($err)->toBeTrue()->and($msg)->toContain('Only the person who created');
    [$err] = mcpTool($token, 'recipes_delete', ['recipe_id' => $r['id']]);
    expect($err)->toBeFalse()->and(Recipe::withTrashed()->find($r['id'])->trashed())->toBeTrue();
});

it('serves the recipe widget with the calculator inlined and images allowed from this app only', function () {
    [, , $token] = recipesToken(['recipes' => 'read']);
    $tools = collect(mcpCall($token, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($tools['recipes_get']['_meta']['ui']['resourceUri'])->toBe('ui://familywell/recipe');

    $content = mcpCall($token, 'resources/read', ['uri' => Widget::uriFor('recipe')])->json('result.contents.0');
    expect($content['text'])->toContain('function factorFor(')->not->toContain('export function')
        ->and($content['_meta']['ui']['csp']['resourceDomains'])->toContain(rtrim(config('app.url'), '/'));
});
